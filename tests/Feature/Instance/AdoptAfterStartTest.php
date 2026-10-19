<?php

declare(strict_types=1);

use App\Domain\Instance\Docker\DockerCommandFailed;
use App\Domain\Instance\Docker\ProcessOutcome;
use App\Domain\Instance\Operations\OperationJournal;
use Tests\Support\Instance\AdoptFixture;

/*
 * `fsa adopt` from `start` on (plan U19, ADR 0087, verification review V1, A1, V4), against the recording Docker gateway:
 * 1.0 must not have run since adopt stopped it, checked before `start` on every run (a resumed one past `start` too) and
 * again right after it; when it ran, the run stops with exit 4 `legacy_restarted`, the journal stays open and nothing goes
 * back. A resumed run past `start` no longer reads the checkout. Uninstall says whether 1.0 ran since the adopt.
 */

beforeEach(function (): void {
    $this->adopt = new AdoptFixture;
});

afterEach(function (): void {
    $this->adopt->cleanup();
});

/** Makes 2.0's `start` (compose up of every service) fail until the returned closure is called. */
function failStart(AdoptFixture $adopt): Closure
{
    $docker = $adopt->instance->docker;
    $start = $docker->onCall['composeUp'];
    $docker->onCall['composeUp'] = static function (array $args) use ($start): void {
        if ($args['services'] === []) {
            throw new DockerCommandFailed('compose up', new ProcessOutcome(1, '', 'app is unhealthy'));
        }
        $start($args);
    };

    return static function () use ($docker, $start): void {
        $docker->onCall['composeUp'] = $start;
    };
}

/** The operator used 1.0 meanwhile and stopped it again. */
function useLegacyAndStop(AdoptFixture $adopt): void
{
    $adopt->restartLegacy();
    foreach (AdoptFixture::SERVICES as $service) {
        $adopt->instance->docker->stopContainer($adopt->legacyId($service));
    }
}

it('stops a resumed run past start when 1.0 ran meanwhile: exit 4, journal open, nothing goes back (review V1)', function (): void {
    $fixed = failStart($this->adopt);
    [$failed] = $this->adopt->adopt();
    $fixed();
    useLegacyAndStop($this->adopt);
    $docker = $this->adopt->instance->docker;
    $ups = count($docker->called('composeUp'));

    [$code, $json] = $this->adopt->adopt();

    expect($failed)->toBe(4)
        ->and($code)->toBe(4, (string) json_encode($json))
        ->and($json['errors'][0]['code'])->toBe('legacy_restarted')
        ->and($json['errors'][0]['message'])->toContain('app was started again')
        ->and($json['errors'][0]['hint'])->toContain('fsa uninstall')->toContain('fsa adopt '.$this->adopt->checkout)
        ->and(count($docker->called('composeUp')))->toBe($ups)
        ->and($docker->called('removeVolume'))->toBe([])->and($docker->called('removeContainer'))->toBe([])
        ->and($this->adopt->legacyCalls('startContainers'))->toBe([])
        ->and(app(OperationJournal::class)->current()?->operation())->toBe('adopt');
});

it('stops right after start when 1.0 started between the last check and 2.0\'s start (review A1)', function (): void {
    $docker = $this->adopt->instance->docker;
    $start = $docker->onCall['composeUp'];
    $queue = $this->adopt->legacyId('queue');
    $docker->onCall['composeUp'] = static function (array $args) use ($start, $docker, $queue): void {
        if ($args['services'] === []) {
            $docker->restartContainer($queue, '2026-10-20T12:00:00Z'); // 1.0's queue came back just before
        }
        $start($args);
    };

    [$code, $json] = $this->adopt->adopt();

    expect($code)->toBe(4, (string) json_encode($json))
        ->and($json['errors'][0]['code'])->toBe('legacy_restarted')
        ->and($json['errors'][0]['message'])->toContain('queue runs')
        ->and($this->adopt->instance->oneOffCalls('instance:pdf-test'))->toBe([])
        ->and($docker->called('removeVolume'))->toBe([])
        ->and(app(OperationJournal::class)->current()?->operation())->toBe('adopt');
});

it('finishes a resumed run past start without reading the changed checkout (review V4)', function (): void {
    $fixed = failStart($this->adopt);
    $this->adopt->adopt();
    $fixed();
    file_put_contents($this->adopt->checkout.'/.env', AdoptFixture::env(['COMPOSE_FILE' => 'compose.yaml:compose.override.yaml']));

    [$code, $json] = $this->adopt->adopt();

    expect($code)->toBe(0, (string) json_encode($json))
        ->and(app(OperationJournal::class)->current())->toBeNull();
});

it('says at uninstall whether 1.0 ran since the adopt (review V1)', function (bool $used, string $says, string $never): void {
    $this->adopt->adopt();
    if ($used) {
        useLegacyAndStop($this->adopt);
    }

    [$code, $json] = $this->adopt->instance->run('fsa:uninstall', ['--yes' => true]);
    $restart = collect($json['warnings'])->firstWhere('code', 'restart_1_0');

    expect($code)->toBe(0, (string) json_encode($json))
        ->and($restart['message'] ?? '')->toContain($says)->not->toContain($never)
        ->and($json['data']['adopted']['legacy_changed'] ?? null)->toBe($used);
})->with([
    'untouched' => [false, 'its volumes were not changed since', 'was started again'],
    'used meanwhile' => [true, 'was started again since the adopt', 'were not changed'],
]);
