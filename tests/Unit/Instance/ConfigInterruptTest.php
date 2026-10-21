<?php

declare(strict_types=1);

use App\Domain\Instance\Config\ConfigRequest;
use App\Domain\Instance\Docker\ContainerSpec;
use App\Domain\Instance\Operations\Interruption;
use Tests\Support\Instance\ConfigFixture;
use Tests\Support\Instance\TemporaryInstance;

/*
 * M10 gate (ADR 0078): a foreground `fieldset config set` that gets SIGTERM, SIGINT or SIGHUP (an agent's tool timeout,
 * Ctrl-C, a closed SSH session) must not leave an unverified configuration applied with its journal open. During
 * apply or verify the signal starts the journaled revert, which runs to its end even through a second signal; before
 * any change it aborts with exit 9 and nothing changed. Real signals, sent to this process.
 */

beforeEach(function (): void {
    if (! Interruption::supported()) {
        $this->markTestSkipped('pcntl is not available in this PHP.');
    }
    $this->config = new ConfigFixture;
});

afterEach(fn () => TemporaryInstance::cleanup());

function sendSignal(int $signal): void
{
    posix_kill(getmypid(), $signal);
    usleep(1000); // the asynchronous handler runs here at the latest
}

it('reverts a change interrupted during apply: the journal ends rolled_back and the previous settings run', function (): void {
    $ups = 0;
    $this->config->instance->docker->onCall['composeUp'] = function () use (&$ups): void {
        if (++$ups === 1) {
            sendSignal(SIGTERM);
        }
    };

    [$code, $json] = $this->config->set('port', '8090', ['--yes' => true]);

    $journal = $this->config->archivedJournal();
    expect($code)->toBe(5, json_encode($json))
        ->and($json['status'])->toBe('rolled_back')
        ->and($json['errors'][0])->toMatchArray(['code' => 'interrupted', 'retryable' => true])
        ->and($json['data']['retry_after'])->toBe(0)
        ->and($json['data']['failure'])->toBe(['signal' => 'SIGTERM'])
        ->and($this->config->state()->setting('port'))->toBe(8080)
        ->and($this->config->openJournal())->toBeNull()
        ->and($journal->outcome()?->value)->toBe('rolled_back')
        ->and(ConfigFixture::phases($journal))->toBe(['snapshot:completed', 'preflight:completed', 'apply:failed', 'revert:completed'])
        ->and(collect($journal->events())->pluck('type')->all())->toContain('reverted')
        ->and(pcntl_signal_get_handler(SIGTERM))->toBe(SIG_DFL);
});

it('finishes the revert when a second signal arrives during it', function (): void {
    $ups = 0;
    $this->config->instance->docker->onCall['composeUp'] = function () use (&$ups): void {
        sendSignal(++$ups === 1 ? SIGHUP : SIGINT); // the second one hits the revert's `up`
    };

    [$code, $json] = $this->config->set('port', '8090', ['--yes' => true]);

    expect($code)->toBe(5, json_encode($json))
        ->and($json['errors'][0]['code'])->toBe('interrupted')
        ->and($json['data']['failure'])->toBe(['signal' => 'SIGHUP'])
        ->and(ConfigFixture::phases($this->config->archivedJournal()))->toContain('revert:completed');
});

it('reverts a change interrupted while waiting for the verification', function (): void {
    $this->config->smokeFails = function (): bool {
        sendSignal(SIGTERM);

        return false;
    };

    [$code, $json] = $this->config->set('port', '8090', ['--yes' => true]);

    expect($code)->toBe(5, json_encode($json))
        ->and(ConfigFixture::phases($this->config->archivedJournal()))->toBe(['snapshot:completed', 'preflight:completed', 'apply:completed', 'verify:failed', 'revert:completed']);
});

it('aborts with exit 9 and nothing changed when the signal arrives before any change', function (): void {
    $this->config->instance->docker->beforeRun = function (ContainerSpec $spec): void {
        if ($spec->entrypoint === 'true') { // the preflight's port probe
            sendSignal(SIGINT);
        }
    };
    $mark = $this->config->mark();

    [$code, $json] = $this->config->set('port', '8090', ['--yes' => true]);

    expect($code)->toBe(9, json_encode($json))
        ->and($json['changed'])->toBeFalse()
        ->and($json['errors'][0]['code'])->toBe('interrupted')
        ->and($this->config->callsSince($mark, 'composeUp'))->toBe([])
        ->and($this->config->state()->setting('port'))->toBe(8080)
        ->and($this->config->openJournal())->toBeNull();
});

it('reverts the change a killed run left applied when a resumed run is interrupted before its own apply', function (): void {
    $this->config->interruptedDuringApply(new ConfigRequest('port', '8090')); // state.json holds the unverified 8090
    $guard = new Interruption;
    $guard->handle(SIGTERM); // arrived while the resumed run took the lock (disarmed: recorded)
    app()->instance(Interruption::class, $guard);

    [$code, $json] = $this->config->set('port', '8090', ['--yes' => true]);

    expect($code)->toBe(5, json_encode($json))
        ->and($json['errors'][0]['code'])->toBe('interrupted')
        ->and($json['data']['reverted'])->toBeTrue()
        ->and($this->config->state()->setting('port'))->toBe(8080)
        ->and($this->config->openJournal())->toBeNull()
        ->and(collect($this->config->archivedJournals())->first()?->outcome()?->value)->toBe('rolled_back');
});

it('aborts a fresh run with exit 9 when a signal arrived while it took the lock', function (): void {
    $guard = new Interruption;
    $guard->handle(SIGINT);
    app()->instance(Interruption::class, $guard);
    $mark = $this->config->mark();

    [$code, $json] = $this->config->set('port', '8090', ['--yes' => true]);

    expect($code)->toBe(9, json_encode($json))
        ->and($json['changed'])->toBeFalse()
        ->and($this->config->callsSince($mark, 'composeUp'))->toBe([])
        ->and($this->config->openJournal())->toBeNull();
});

it('keeps a verified change when the signal arrives during the audit', function (): void {
    $this->config->instance->docker->respondToComposeRun(function (array $command): null {
        if (($command[2] ?? null) === 'instance:config-changed') {
            sendSignal(SIGTERM);
        }

        return null;
    });

    [$code, $json] = $this->config->set('port', '8090', ['--yes' => true]);

    expect($code)->toBe(0, json_encode($json))
        ->and($this->config->state()->setting('port'))->toBe(8090)
        ->and($this->config->openJournal())->toBeNull();
});
