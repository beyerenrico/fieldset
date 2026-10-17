<?php

declare(strict_types=1);

use App\Domain\Instance\Docker\ContainerSpec;
use App\Domain\Instance\Docker\DockerCommandFailed;
use App\Domain\Instance\Docker\Labels;
use App\Domain\Instance\Docker\ProcessOutcome;
use App\Domain\Instance\InstanceException;
use App\Domain\Instance\Operations\InstanceLock;
use App\Domain\Instance\Operations\LockHeld;
use App\Domain\Instance\Operations\OperationJournal;
use App\Domain\Instance\Operations\OperationRun;
use App\Domain\Instance\Operations\OperationUnfinished;
use App\Domain\Instance\Operations\Supervisor;
use App\Domain\Instance\Output\CommandResult;
use App\Domain\Instance\Output\ExitCode;
use App\Domain\Instance\Output\ResultStatus;
use Tests\Support\Instance\RecordingDockerGateway;
use Tests\Support\Instance\TemporaryInstance;

const SUPERVISOR_STARTED = '2026-10-17T10:00:00.000000001Z';

beforeEach(function (): void {
    $this->directory = TemporaryInstance::withState();
    $this->docker = new RecordingDockerGateway;
    $this->lock = new InstanceLock($this->directory, $this->docker);
    $this->journal = new OperationJournal($this->directory);
    $this->supervisor = new Supervisor($this->lock, $this->journal, $this->docker);
    $this->holder = TemporaryInstance::holder('a', SUPERVISOR_STARTED);
    $this->docker->withRunningContainer($this->holder->containerId, SUPERVISOR_STARTED);
});

afterEach(fn () => TemporaryInstance::cleanup());

function installSteps(array &$ran, ?Closure $smoke = null): Closure
{
    return function (OperationRun $run) use (&$ran, $smoke): CommandResult {
        $run->phase('doctor', function () use (&$ran): array {
            $ran[] = 'doctor';

            return ['port' => 8080];
        }, changes: false);
        $run->phase('up', function () use (&$ran): array {
            $ran[] = 'up';

            return [];
        });
        $run->phase('smoke', $smoke ?? function () use (&$ran): array {
            $ran[] = 'smoke';

            return [];
        });

        return CommandResult::ok('install', ['port' => $run->resumeData()['port']], changed: true);
    };
}

it('runs the steps under the lock, journals each phase and archives the finished operation', function (): void {
    $ran = [];

    $result = $this->supervisor->run('install', $this->holder, installSteps($ran));

    $history = $this->journal->history();
    expect($result->exitCode)->toBe(ExitCode::Success)
        ->and($result->data)->toBe(['port' => 8080])
        ->and($ran)->toBe(['doctor', 'up', 'smoke'])
        ->and($this->lock->current())->toBeNull()
        ->and($this->journal->current())->toBeNull()
        ->and($history[0]->outcome())->toBe(ResultStatus::Ok)
        ->and($history[0]->hostUid())->toBe(501)
        ->and(array_column($history[0]->phases(), 'name'))->toBe(['doctor', 'up', 'smoke']);
});

it('records a phase as started before its step runs', function (): void {
    $seen = null;
    $this->supervisor->run('install', $this->holder, function (OperationRun $run) use (&$seen): CommandResult {
        $run->phase('pull', function () use (&$seen): array {
            $seen = new OperationJournal($this->directory)->current()?->lastStartedPhase();

            return [];
        });

        return CommandResult::ok('install');
    });

    expect($seen)->toBe('pull');
});

it('refuses to start while another live operation holds the lock', function (): void {
    $other = TemporaryInstance::holder('b', SUPERVISOR_STARTED);
    $this->docker->withRunningContainer($other->containerId, SUPERVISOR_STARTED);
    $this->lock->acquire($other, 'upgrade');
    $ran = [];

    expect(fn () => $this->supervisor->run('install', $this->holder, installSteps($ran)))->toThrow(LockHeld::class)
        ->and($ran)->toBe([]);
});

it('journals the takeover of a stale lock', function (): void {
    $dead = TemporaryInstance::holder('b', SUPERVISOR_STARTED);
    $this->lock->acquire($dead, 'install');
    $ran = [];

    $this->supervisor->run('install', $this->holder, installSteps($ran));

    $events = $this->journal->history()[0]->events();
    expect($events[0]['type'])->toBe('lock_takeover')
        ->and($events[0]['data']['previous']['container_id'])->toBe(str_repeat('b', 64))
        ->and($events[0]['data']['previous']['operation'])->toBe('install');
});

it('journals a takeover into the unfinished operation it then refuses to override', function (): void {
    $this->journal->begin('upgrade', 501);
    $this->journal->startPhase('migrate');
    $this->lock->acquire(TemporaryInstance::holder('b', SUPERVISOR_STARTED), 'upgrade');
    $ran = [];

    expect(fn () => $this->supervisor->run('install', $this->holder, installSteps($ran)))->toThrow(OperationUnfinished::class);

    expect($this->journal->current()?->events()[0]['type'])->toBe('lock_takeover')
        ->and($this->lock->current())->toBeNull()
        ->and($ran)->toBe([]);
});

it('exits 9 and archives the journal when it fails before changing anything (R69)', function (): void {
    $result = $this->supervisor->run('install', $this->holder, function (OperationRun $run): CommandResult {
        $run->phase('pull', fn (): array => throw new InstanceException('registry unreachable'), changes: false);

        return CommandResult::ok('install');
    });

    expect($result->exitCode)->toBe(ExitCode::AbortedUnchanged)
        ->and($result->status())->toBe(ResultStatus::Aborted)
        ->and($result->changed)->toBeFalse()
        ->and($result->errors[0]->message)->toBe('registry unreachable')
        ->and($this->journal->current())->toBeNull()
        ->and($this->journal->history()[0]->outcome())->toBe(ResultStatus::Aborted)
        ->and($this->lock->current())->toBeNull();
});

it('keeps a specific pre-change exit code such as a failed preflight', function (): void {
    $result = $this->supervisor->run('install', $this->holder, function (OperationRun $run): CommandResult {
        $run->phase('doctor', fn (): array => throw new InstanceException('port taken', ExitCode::PreflightFailed, 'port_taken'), changes: false);

        return CommandResult::ok('install');
    });

    expect($result->exitCode)->toBe(ExitCode::PreflightFailed)
        ->and($this->journal->current())->toBeNull();
});

it('leaves the journal open after a failure that changed something, and resumes at the failed phase', function (): void {
    $ran = [];
    $failed = $this->supervisor->run('install', $this->holder, installSteps($ran, fn (): array => throw new InstanceException('unhealthy', ExitCode::HealthCheckFailed, 'smoke_failed')));

    expect($failed->exitCode)->toBe(ExitCode::HealthCheckFailed)
        ->and($failed->changed)->toBeTrue()
        ->and($this->journal->current()?->lastStartedPhase())->toBe('smoke')
        ->and($this->lock->current())->toBeNull();

    $ran = [];
    $resumed = $this->supervisor->run('install', $this->holder, installSteps($ran), resume: true);

    $history = $this->journal->history()[0];
    expect($resumed->exitCode)->toBe(ExitCode::Success)
        ->and($resumed->data)->toBe(['port' => 8080])
        ->and($ran)->toBe(['smoke'])
        ->and(array_column($history->events(), 'type'))->toBe(['failed', 'resumed'])
        ->and(array_column($history->phases(), 'status'))->toBe(['completed', 'completed', 'failed', 'completed']);
});

it('refuses to resume an unfinished operation of another kind', function (): void {
    $this->journal->begin('upgrade', 501);
    $ran = [];

    expect(fn () => $this->supervisor->run('install', $this->holder, installSteps($ran), resume: true))->toThrow(OperationUnfinished::class)
        ->and($this->lock->current())->toBeNull();
});

it('archives a rolled-back operation and keeps a failed rollback open', function (ExitCode $code, bool $open): void {
    $result = $this->supervisor->run('upgrade', $this->holder, function (OperationRun $run) use ($code): CommandResult {
        $run->phase('migrate', fn (): array => []);

        return CommandResult::failed('upgrade', $code, 'migration_failed', 'Migration failed.');
    });

    expect($result->exitCode)->toBe($code)
        ->and($this->journal->current() !== null)->toBe($open);
})->with([
    'rolled back' => [ExitCode::RolledBack, false],
    'rollback failed' => [ExitCode::RollbackFailed, true],
]);

it('marks the phase failed and releases the lock when a step throws an unexpected error', function (): void {
    expect(fn () => $this->supervisor->run('install', $this->holder, function (OperationRun $run): CommandResult {
        $run->phase('up', fn (): array => throw new RuntimeException('boom'));

        return CommandResult::ok('install');
    }))->toThrow(RuntimeException::class, 'boom');

    expect($this->journal->current()?->phases()[0]['status'])->toBe('failed')
        ->and($this->lock->current())->toBeNull();
});

it('starts one detached, labelled supervisor container per instance', function (): void {
    $state = $this->directory->state();

    $id = $this->supervisor->launchDetached($state, new ContainerSpec('ghcr.io/x/app@sha256:'.str_repeat('1', 64), ['fsa', 'upgrade', '--supervise']));

    $spec = $this->docker->called('runContainer')[0]['spec'];
    expect($id)->toBe(str_repeat('c', 64))
        ->and($spec->name)->toBe($state->project().'-supervisor')
        ->and($spec->detach)->toBeTrue()
        ->and($spec->remove)->toBeTrue()
        ->and($spec->restart)->toBe('no')
        ->and($spec->labels)->toBe([Labels::INSTANCE => $state->uuid(), Labels::ROLE => 'supervisor']);
});

it('refuses a second supervisor container while one runs', function (): void {
    $state = $this->directory->state();
    $this->docker->withRunningContainer(str_repeat('s', 64), SUPERVISOR_STARTED, Labels::forInstance($state->uuid(), 'supervisor'));

    expect(fn () => $this->supervisor->launchDetached($state, new ContainerSpec('img')))
        ->toThrow(InstanceException::class, 'already running');
    expect($this->docker->called('runContainer'))->toBe([]);
});

it('reports a supervisor container that could not start', function (): void {
    $this->docker->queueOutcome('runContainer', new ProcessOutcome(125, '', 'no such image'));

    expect(fn () => $this->supervisor->launchDetached($this->directory->state(), new ContainerSpec('img')))
        ->toThrow(DockerCommandFailed::class);
});
