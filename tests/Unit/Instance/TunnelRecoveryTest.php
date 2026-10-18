<?php

declare(strict_types=1);

use App\Domain\Instance\Demo\DemoOperation;
use App\Domain\Instance\Operations\Interruption;
use App\Domain\Instance\Operations\JournalRecord;
use App\Domain\Instance\Operations\OperationJournal;
use App\Domain\Instance\Operations\OperationUnfinished;
use App\Domain\Instance\State\RuntimeWriter;
use App\Domain\Instance\Tunnel\TunnelOperation;
use Carbon\CarbonImmutable;
use Tests\Support\Instance\ConfigFixture;
use Tests\Support\Instance\DemoFixtures;
use Tests\Support\Instance\TemporaryInstance;
use Tests\Support\Instance\TunnelFixture;

/*
 * M11 gate (A1; ADR 0078, ADR 0082): an unfinished `tunnel` journal must never block the instance for good. `fsa tunnel
 * stop` finishes any of them whatever the tunnel status says and converges to the state on disk; the next mutating
 * command does the same unless a live tunnel would go; the exit-9 hint names `fsa tunnel stop`. Tunnel and demo
 * operations survive SIGTERM, SIGINT and SIGHUP like `config set` (Interruption). Real signals (SIGTERM and SIGHUP:
 * Pest handles SIGINT itself), sent to this process only while a handler is installed, or the runner would die.
 */

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-10-18T12:00:00Z');
    $this->fixture = new TunnelFixture;
    $this->directory = $this->fixture->config->instance->directory;
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
    TemporaryInstance::cleanup();
});

/** Sends the signal to this process when a handler catches it; returns whether one did. */
function tunnelSignal(int $signal): bool
{
    if (! Interruption::supported() || pcntl_signal_get_handler($signal) === SIG_DFL) {
        return false;
    }
    posix_kill(getmypid(), $signal);
    usleep(1000); // the asynchronous handler runs here at the latest

    return true;
}

/** A tunnel journal as a command killed with SIGKILL leaves it: begun, its last phase started. */
function tunnelJournalCutOff(object $test, string $action, string $phase, bool $changes = true): void
{
    $journal = new OperationJournal($test->directory);
    $journal->begin('tunnel', posix_getuid(), ['action' => $action]);
    $journal->startPhase($phase, [], $changes);
}

it('finishes a start whose revert failed, though the status says none, and nothing stays blocked (A1a)', function (string $command, array $parameters): void {
    $this->fixture->hostname = null;
    $this->fixture->failComposeUps(2); // the revert's `up`
    [$code, $json] = $this->fixture->tunnel('start');
    [, $status] = $this->fixture->config->instance->run('fsa:status');
    $this->fixture->failComposeUps();

    [$next, $nextJson] = $this->fixture->config->instance->run($command, $parameters);

    expect($code)->toBe(8, json_encode($json))
        ->and($status['data']['tunnel'])->toBeNull()
        ->and($status['data']['operation']['command'])->toBe('fsa tunnel stop')
        ->and($next)->toBe(0, json_encode($nextJson))
        ->and($nextJson['changed'])->toBeTrue()
        ->and($this->fixture->config->openJournal())->toBeNull()
        ->and($this->fixture->tunnelContainer())->toBeNull()
        ->and($this->fixture->config->state()->setting('phone_https'))->toBeNull()
        ->and(collect($this->fixture->config->archivedJournals('tunnel'))->map(fn (JournalRecord $record): string => implode(',', ConfigFixture::phases($record)))->all())
        ->toContain('accounts:completed,preflight:completed,start:failed,revert:failed,remove:completed');
})->with([
    'fsa tunnel stop' => ['fsa:tunnel', ['action' => 'stop']],
    'the next config set' => ['fsa:config', ['action' => 'set', 'key' => 'port', 'value' => '8090', '--yes' => true]],
]);

it('names `fsa tunnel stop` when an unfinished tunnel operation blocks another command beside a live tunnel (A1)', function (): void {
    $this->fixture->tunnel('start');
    tunnelJournalCutOff($this, 'renew', 'renew'); // a renew killed while it waited for the hostname

    [$blocked, $blockedJson] = $this->fixture->config->set('port', '8090', ['--yes' => true]);
    [$stop, $stopJson] = $this->fixture->tunnel('stop');

    expect($blocked)->toBe(9)
        ->and($blockedJson['errors'][0]['code'])->toBe('operation_unfinished')
        ->and($blockedJson['data']['command'])->toBe('fsa tunnel stop')
        ->and($blockedJson['errors'][0]['hint'])->toContain('`fsa tunnel stop`')
        ->and($stop)->toBe(0, json_encode($stopJson))
        ->and($this->fixture->config->openJournal())->toBeNull()
        ->and($this->fixture->tunnelContainer())->toBeNull()
        ->and($this->fixture->config->set('port', '8090', ['--yes' => true])[0])->toBe(0);
});

it('converges a stop killed after it saved the state, though nothing runs any more by the state (A1b)', function (): void {
    $this->fixture->tunnel('start');
    $state = $this->fixture->config->state();
    tunnelJournalCutOff($this, 'stop', 'remove');
    new RuntimeWriter($this->directory)->writeTunnel($state, null);
    $this->directory->saveState($state->withSetting('phone_https', null));
    $mark = $this->fixture->config->mark();

    [$code, $json] = $this->fixture->tunnel('stop');

    expect($code)->toBe(0, json_encode($json))
        ->and($json['changed'])->toBeTrue()
        ->and($this->fixture->config->methodsSince($mark))->toContain('composeStop', 'composeUp')
        ->and($this->fixture->tunnelContainer())->toBeNull()
        ->and($this->fixture->config->openJournal())->toBeNull();
});

it('reverts a start interrupted while the stack changes: exit 5, retry at once, the journal closed (A1b)', function (): void {
    $sent = false;
    $previous = $this->fixture->instance->docker->onCall['composeUp'];
    $this->fixture->instance->docker->onCall['composeUp'] = function (array $args) use ($previous, &$sent): void {
        $previous($args);
        $sent = $sent || tunnelSignal(SIGTERM);
    };

    [$code, $json] = $this->fixture->tunnel('start');

    expect($sent)->toBeTrue('no signal handler was installed during tunnel start')
        ->and($code)->toBe(5, json_encode($json))
        ->and($json['errors'][0])->toMatchArray(['code' => 'interrupted', 'retryable' => true])
        ->and($json['data']['retry_after'])->toBe(0)
        ->and($this->fixture->config->state()->setting('phone_https'))->toBeNull()
        ->and($this->fixture->tunnelContainer())->toBeNull()
        ->and($this->fixture->config->openJournal())->toBeNull()
        ->and($this->fixture->config->archivedJournal('tunnel')->outcome()?->value)->toBe('rolled_back')
        ->and(pcntl_signal_get_handler(SIGTERM))->toBe(SIG_DFL);
});

it('finishes a stop through a signal: the tunnel goes and the journal closes (A1b)', function (): void {
    $this->fixture->tunnel('start');
    $sent = false;
    $previous = $this->fixture->instance->docker->onCall['composeUp'];
    $this->fixture->instance->docker->onCall['composeUp'] = function (array $args) use ($previous, &$sent): void {
        $sent = $sent || tunnelSignal(SIGHUP);
        $previous($args);
    };

    [$code, $json] = $this->fixture->tunnel('stop');

    expect($sent)->toBeTrue('no signal handler was installed during tunnel stop')
        ->and($code)->toBe(0, json_encode($json))
        ->and($this->fixture->tunnelContainer())->toBeNull()
        ->and($this->fixture->runtimeTunnel()['hostname'])->toBeNull()
        ->and($this->fixture->config->openJournal())->toBeNull()
        ->and(pcntl_signal_get_handler(SIGHUP))->toBe(SIG_DFL);
});

it('leaves no journal open when a renew fails, so the expired tunnel goes with the next command (A1c)', function (): void {
    $this->fixture->tunnel('start');
    $this->fixture->hostname = null;
    CarbonImmutable::setTestNow('2026-10-18T15:00:00Z');
    [$renew, $renewJson] = $this->fixture->tunnel('renew');
    $expiry = $this->fixture->runtimeTunnel()['expires_at'];
    CarbonImmutable::setTestNow('2026-10-18T16:00:01Z');

    [$code, $json] = $this->fixture->config->set('port', '8090', ['--yes' => true]);

    expect($renew)->toBe(9, json_encode($renewJson))
        ->and($renewJson['errors'][0]['code'])->toBe('tunnel_unavailable')
        ->and($renewJson['changed'])->toBeFalse()
        ->and($expiry)->toBe('2026-10-18T16:00:00Z')
        ->and($code)->toBe(0, json_encode($json))
        ->and(collect($json['warnings'])->pluck('code')->all())->toContain('tunnel_removed')
        ->and($this->fixture->tunnelContainer())->toBeNull();
});

it('lets the next command finish a cut-off renew once the tunnel expired (A1c)', function (): void {
    $this->fixture->tunnel('start');
    tunnelJournalCutOff($this, 'renew', 'renew');
    CarbonImmutable::setTestNow('2026-10-18T16:00:01Z');

    [$code, $json] = $this->fixture->config->instance->run('fsa:demo', ['--rotate-passwords' => true]);

    expect($code)->toBe(0, json_encode($json))
        ->and(collect($json['warnings'])->pluck('code')->all())->toContain('tunnel_removed')
        ->and($this->fixture->tunnelContainer())->toBeNull()
        ->and($this->fixture->config->openJournal())->toBeNull();
});

it('does not loop on exit 9 when the expiry cleanup of `config set` fails (A1d)', function (string $failing): void {
    $this->fixture->tunnel('start');
    CarbonImmutable::setTestNow('2026-10-18T16:00:01Z');
    if ($failing === 'compose up') {
        $this->fixture->failComposeUps(1);
    } else {
        $this->fixture->instance->docker->failOn('composeStop', new RuntimeException('Cannot connect to the Docker daemon'));
    }
    [$first, $firstJson] = $this->fixture->config->set('port', '8090', ['--yes' => true]);
    $this->fixture->failComposeUps();
    $this->fixture->instance->docker->failures = [];

    [$stop, $stopJson] = $this->fixture->tunnel('stop');
    [$again, $againJson] = $this->fixture->config->set('port', '8090', ['--yes' => true]);

    expect($first)->toBe(9, json_encode($firstJson))
        ->and(collect($firstJson['warnings'])->pluck('code')->all())->toContain('tunnel_cleanup_failed')
        ->and($firstJson['data']['command'] ?? null)->toBe('fsa tunnel stop')
        ->and($stop)->toBe(0, json_encode($stopJson))
        ->and($stopJson['changed'])->toBeTrue()
        ->and($this->fixture->tunnelContainer())->toBeNull()
        ->and($again)->toBe(0, json_encode($againJson))
        ->and($this->fixture->config->openJournal())->toBeNull();
})->with(['compose up', 'compose stop']);

it('names the command that resumes an unfinished tunnel or demo operation (A1)', function (string $operation, array $context, string $command): void {
    $record = JournalRecord::start('20261018T120000-x', $operation, 501, $context, '2026-10-18T12:00:00Z');

    expect(OperationUnfinished::commandLine($record))->toBe($command)
        ->and(OperationUnfinished::hint($record))->toContain("`{$command}`");
})->with([
    'a tunnel start' => [TunnelOperation::OPERATION, ['action' => 'start', 'admin' => false], 'fsa tunnel stop'],
    'a tunnel renew' => [TunnelOperation::OPERATION, ['action' => 'renew'], 'fsa tunnel stop'],
    'a demo rotation' => [DemoOperation::OPERATION, ['rotate' => true], 'fsa demo --rotate-passwords'],
    'a demo' => [DemoOperation::OPERATION, ['rotate' => false], 'fsa demo'],
]);

it('finishes a demo password rotation through a signal and lists the passwords (A1)', function (): void {
    $sent = false;
    $this->fixture->instance->docker->onCall['composeRun'] = function (array $args) use (&$sent): void {
        if (in_array('--rotate', $args['command'], true)) {
            $sent = tunnelSignal(SIGTERM);
        }
    };

    [$code, $json] = $this->fixture->config->instance->run('fsa:demo', ['--rotate-passwords' => true]);

    expect($sent)->toBeTrue('no signal handler was installed during fsa demo')
        ->and($code)->toBe(0, json_encode($json))
        ->and(collect($json['secrets'])->pluck('name')->all())->toContain('demo_password:'.DemoFixtures::EMAILS[0])
        ->and($this->fixture->config->openJournal())->toBeNull()
        ->and(pcntl_signal_get_handler(SIGTERM))->toBe(SIG_DFL);
});
