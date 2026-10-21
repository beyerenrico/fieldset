<?php

declare(strict_types=1);

use App\Domain\Instance\Docker\DockerCommandFailed;
use App\Domain\Instance\Docker\DockerUnavailable;
use App\Domain\Instance\Docker\ProcessOutcome;
use Tests\Support\Instance\ConfigFixture;
use Tests\Support\Instance\TemporaryInstance;

/*
 * M10 gate (ADR 0078, amends ADR 0077): what `fieldset config set` reports when a change fails. A change that was reverted
 * is `rolled_back` with exit 5 (§7), retryable only for transient engine trouble (`data.retry_after`), otherwise
 * with a `data.fix_first` marker; `data.url` and `data.mode` are always strings; a failed revert says exactly which
 * of its steps completed and stops only what serves requests (app roles, edge, catcher), never the databases.
 */

beforeEach(function (): void {
    $this->config = new ConfigFixture;
});

afterEach(fn () => TemporaryInstance::cleanup());

it('reports a reverted change as rolled_back with exit 5 and a fix_first marker for a certificate failure', function (): void {
    $this->config->certificate = false;

    [$code, $json] = $this->config->set('domain', ConfigFixture::DOMAIN, ['--yes' => true]);

    expect($code)->toBe(5, json_encode($json))
        ->and($json['status'])->toBe('rolled_back')
        ->and($json['changed'])->toBeFalse()
        ->and($json['errors'][0])->toMatchArray(['code' => 'certificate_failed', 'retryable' => false])
        ->and($json['data']['fix_first'])->toBe($json['errors'][0]['hint'])
        ->and($json['data'])->not->toHaveKey('retry_after')
        ->and($json['data'])->toMatchArray(['reverted' => true, 'url' => 'http://localhost:8080', 'mode' => 'local'])
        ->and($json['data']['command'])->toBe('fieldset config set domain '.ConfigFixture::DOMAIN.' --yes')
        ->and($this->config->archivedJournal()->outcome()?->value)->toBe('rolled_back');
});

it('marks a revert after a transient engine error as retryable with retry_after', function (): void {
    $ups = 0;
    $this->config->instance->docker->onCall['composeUp'] = function () use (&$ups): void {
        if (++$ups === 1) {
            throw new DockerUnavailable('The Docker daemon is not reachable: connection reset');
        }
    };

    [$code, $json] = $this->config->set('port', '8090', ['--yes' => true]);

    expect($code)->toBe(5, json_encode($json))
        ->and($json['errors'][0])->toMatchArray(['code' => 'docker_unavailable', 'retryable' => true])
        ->and($json['data']['retry_after'])->toBe(30)
        ->and($json['data'])->not->toHaveKey('fix_first');
});

it('is not retryable for an internal error or unhealthy services', function (string $kind, string $error): void {
    $ups = 0;
    $this->config->instance->docker->onCall['composeUp'] = function () use (&$ups, $kind): void {
        if (++$ups === 1) {
            throw $kind === 'internal' ? new RuntimeException('bug') : new DockerCommandFailed('compose up', new ProcessOutcome(1, '', 'container app is unhealthy'));
        }
    };

    [$code, $json] = $this->config->set('port', '8090', ['--yes' => true]);

    expect($code)->toBe(5)
        ->and($json['errors'][0])->toMatchArray(['code' => $error, 'retryable' => false])
        ->and($json['data'])->toHaveKey('fix_first');
})->with([
    'internal error' => ['internal', 'internal_error'],
    'unhealthy services' => ['unhealthy', 'services_unhealthy'],
]);

it('keeps data.url and data.mode strings when a URL change needs confirmation, with the change under data.change', function (): void {
    [$code, $json] = $this->config->set('domain', ConfigFixture::DOMAIN);

    expect($code)->toBe(6)
        ->and($json['data'])->toMatchArray(['key' => 'domain', 'url' => 'http://localhost:8080', 'mode' => 'local'])
        ->and($json['data']['change'])->toMatchArray([
            'url' => ['from' => 'http://localhost:8080', 'to' => 'https://'.ConfigFixture::DOMAIN],
            'mode' => ['from' => 'local', 'to' => 'domain'],
        ]);
});

it('says the services did not come back when only the revert\'s files were restored, and stops only the request-serving services', function (): void {
    $this->config->certificate = false;
    $ups = 0;
    $this->config->instance->docker->onCall['composeUp'] = function () use (&$ups): void {
        if (++$ups === 2) {
            throw new DockerCommandFailed('compose up', new ProcessOutcome(1, '', 'port is already allocated'));
        }
    };

    [$code, $json] = $this->config->set('domain', ConfigFixture::DOMAIN, ['--yes' => true]);

    $stops = $this->config->instance->docker->called('composeStop');
    expect($code)->toBe(8, json_encode($json))
        ->and($json['data']['revert_steps'])->toBe(['files'])
        ->and($json['data']['recovery'][0])->toContain('back in state.json')->toContain('did not come up')
        ->and($stops[array_key_last($stops)]['services'])->toEqualCanonicalizing(['app', 'queue', 'scheduler', 'mailpit']);
});

it('does not claim the previous settings are back when the revert could not write them', function (): void {
    if (posix_getuid() === 0) {
        $this->markTestSkipped('root writes into a read-only directory (CI container); runs on the host.');
    }
    $this->config->certificate = false;
    $directory = $this->config->instance->directory;
    $this->config->instance->docker->onCall['composeUp'] = function () use ($directory): void {
        chmod($directory->root, 0500); // the revert's state write fails from here on
    };

    try {
        [$code, $json] = $this->config->set('domain', ConfigFixture::DOMAIN, ['--yes' => true]);
    } finally {
        chmod($directory->root, 0700);
    }

    expect($code)->toBe(8, json_encode($json))
        ->and($json['data']['revert_steps'])->toBe([])
        ->and($json['data']['recovery'][0])->not->toContain('back in state.json')->toContain('may still hold the attempted change')
        ->and(collect($this->config->openJournal()?->events())->pluck('type')->all())->toContain('revert_failed');
});

it('keeps queue and scheduler stopped while the change is applied and verified, and starts them only after', function (): void {
    $mark = $this->config->mark();

    [$code, $json] = $this->config->set('port', '8090', ['--yes' => true]);

    $calls = array_slice($this->config->instance->docker->calls, $mark);
    $first = fn (Closure $match): int => (int) array_key_first(array_filter($calls, $match));
    $last = fn (Closure $match): int => (int) array_key_last(array_filter($calls, $match));
    $stop = $first(fn (array $call): bool => $call['method'] === 'composeStop');
    $ups = array_values(array_filter($calls, fn (array $call): bool => $call['method'] === 'composeUp'));
    $verify = $last(fn (array $call): bool => $call['method'] === 'runContainer' && $call['args']['spec']->entrypoint === 'curl');
    $workers = $last(fn (array $call): bool => $call['method'] === 'composeUp');
    expect($code)->toBe(0, json_encode($json))
        ->and($calls[$stop]['args']['services'])->toBe(['queue', 'scheduler'])
        ->and($calls[$stop]['args']['timeout'])->toBeNull() // their stop grace period lets running jobs finish
        ->and($ups)->toHaveCount(2)
        ->and($ups[0]['args']['services'])->toContain('app', 'mailpit')->not->toContain('queue', 'scheduler')
        ->and($ups[1]['args']['services'])->toBe([])
        ->and($stop)->toBeLessThan($verify)
        ->and($workers)->toBeGreaterThan($verify);
});

it('brings queue and scheduler back on the previous settings when the change reverts', function (): void {
    $calls = 0;
    $this->config->smokeFails = function (array $urls) use (&$calls): bool {
        return in_array('http://app:8080/up', $urls, true) && ++$calls === 1;
    };
    $mark = $this->config->mark();

    [$code] = $this->config->set('port', '8090', ['--yes' => true]);

    $ups = $this->config->callsSince($mark, 'composeUp');
    expect($code)->toBe(5)
        ->and($ups)->toHaveCount(2)
        ->and($ups[1]['services'])->toBe([]) // the revert: the whole previous stack, workers included
        ->and($this->config->state()->setting('port'))->toBe(8080);
});
