<?php

declare(strict_types=1);

use App\Domain\Instance\Operations\OperationJournal;
use App\Domain\Instance\Operations\OperationUnfinished;
use App\Domain\Instance\Output\ExitCode;
use App\Domain\Instance\Output\ResultStatus;
use App\Domain\Instance\State\IncompatibleSchema;
use App\Domain\Instance\State\InvalidState;
use Carbon\CarbonImmutable;
use Symfony\Component\Process\Exception\ProcessSignaledException;
use Symfony\Component\Process\Process;
use Tests\Support\Instance\TemporaryInstance;

beforeEach(function (): void {
    $this->directory = TemporaryInstance::directory();
    $this->journal = new OperationJournal($this->directory);
});

afterEach(function (): void {
    TemporaryInstance::cleanup();
    CarbonImmutable::setTestNow();
});

it('begins an operation with its caller host UID in a 0600 file', function (): void {
    CarbonImmutable::setTestNow('2026-10-17 09:30:00');

    $record = $this->journal->begin('install', 1000, ['mode' => 'local']);

    $file = $this->directory->path(OperationJournal::CURRENT);
    $json = json_decode((string) file_get_contents($file), true);
    expect(fileperms($file) & 0777)->toBe(0600)
        ->and($json['schema_version'])->toBe('1.0')
        ->and($json['operation'])->toBe('install')
        ->and($json['host_uid'])->toBe(1000)
        ->and($json['started_at'])->toBe('2026-10-17T09:30:00Z')
        ->and($record->isFinished())->toBeFalse()
        ->and($record->lastStartedPhase())->toBeNull()
        ->and($record->context())->toBe(['mode' => 'local']);
});

it('records a phase before its step runs, so a fresh reader reports it with the resume data', function (): void {
    $this->journal->begin('upgrade', 1000, ['target' => '2.0.1']);
    $this->journal->startPhase('pull', ['digests' => ['app' => 'ghcr.io/x/app@sha256:'.str_repeat('1', 64)]]);
    $this->journal->completePhase('pull');
    $this->journal->startPhase('backup', ['safety_backup' => '/srv/fsa/backups/safety-1']);

    $reread = new OperationJournal($this->directory)->current();

    expect($reread?->operation())->toBe('upgrade')
        ->and($reread?->lastStartedPhase())->toBe('backup')
        ->and($reread?->lastCompletedPhase())->toBe('pull')
        ->and($reread?->hasCompleted('pull'))->toBeTrue()
        ->and($reread?->hasCompleted('backup'))->toBeFalse()
        ->and($reread?->resumeData())->toMatchArray([
            'target' => '2.0.1',
            'safety_backup' => '/srv/fsa/backups/safety-1',
        ])
        ->and($reread?->resumeData()['digests']['app'])->toEndWith(str_repeat('1', 64));
});

it('survives a process kill between phases', function (): void {
    $process = new Process([PHP_BINARY, base_path('tests/Fixtures/Instance/journal-kill.php'), $this->directory->root]);
    $signal = null;

    try {
        $process->run();
    } catch (ProcessSignaledException $e) {
        $signal = $e->getSignal();
    }

    $record = new OperationJournal($this->directory)->current();

    expect($signal)->toBe(9)
        ->and($record?->operation())->toBe('upgrade')
        ->and($record?->hostUid())->toBe(1000)
        ->and($record?->lastStartedPhase())->toBe('backup')
        ->and($record?->hasCompleted('pull'))->toBeTrue()
        ->and($record?->resumeData()['safety_backup'])->toBe('/srv/fsa/backups/safety-1');
});

it('ignores a temporary file left by a kill in the middle of a write', function (): void {
    $this->journal->begin('restore', 0);
    $this->journal->startPhase('quiesce');
    file_put_contents($this->directory->path('operations/.current.json.tmp-dead'), '{"schema_version":"1.0","operation":"res');

    expect(new OperationJournal($this->directory)->current()?->lastStartedPhase())->toBe('quiesce');
});

it('refuses to begin while an earlier operation is unfinished and names it', function (): void {
    $this->journal->begin('upgrade', 1000);
    $this->journal->startPhase('migrate');

    try {
        $this->journal->begin('install', 1000);
        $this->fail('begin should refuse');
    } catch (OperationUnfinished $e) {
        expect($e->exitCode)->toBe(ExitCode::AbortedUnchanged)
            ->and($e->getMessage())->toContain('upgrade')->toContain('migrate')
            ->and($e->hint)->toContain('fsa upgrade --resume')
            ->and($e->data['operation'])->toBe('upgrade')
            ->and($e->data['last_phase'])->toBe('migrate');
    }
});

it('archives a finished operation and keeps it in the history', function (): void {
    CarbonImmutable::setTestNow('2026-10-17 10:00:00');
    $this->journal->begin('install', 501);
    $this->journal->startPhase('doctor');
    $this->journal->completePhase('doctor', ['port' => 8080]);
    CarbonImmutable::setTestNow('2026-10-17 10:02:00');

    $finished = $this->journal->finish(ResultStatus::Ok, ['url' => 'http://localhost:8080']);

    $history = $this->journal->history();
    expect($finished->outcome())->toBe(ResultStatus::Ok)
        ->and($finished->finishedAt())->toBe('2026-10-17T10:02:00Z')
        ->and($this->journal->current())->toBeNull()
        ->and($history)->toHaveCount(1)
        ->and($history[0]->id())->toBe($finished->id())
        ->and($history[0]->resumeData())->toMatchArray(['port' => 8080, 'url' => 'http://localhost:8080'])
        ->and($this->journal->begin('upgrade', 501)->operation())->toBe('upgrade');
});

it('records failed phases and events such as a lock takeover', function (): void {
    $this->journal->begin('install', 501);
    $this->journal->startPhase('smoke');
    $this->journal->failPhase('smoke', 'Health check failed');
    $record = $this->journal->note('lock_takeover', ['previous_operation' => 'install']);

    expect($record->phases()[0]['status'])->toBe('failed')
        ->and($record->phases()[0]['error'])->toBe('Health check failed')
        ->and($record->lastStartedPhase())->toBe('smoke')
        ->and($record->events()[0]['type'])->toBe('lock_takeover')
        ->and($record->events()[0]['data'])->toBe(['previous_operation' => 'install']);
});

it('counts a re-started phase as a new attempt', function (): void {
    $this->journal->begin('install', 501);
    $this->journal->startPhase('smoke');
    $this->journal->failPhase('smoke', 'down');
    $record = $this->journal->startPhase('smoke');

    expect(array_column($record->phases(), 'attempt'))->toBe([1, 2]);
});

it('keeps keys written by a newer minor version when it rewrites the journal', function (): void {
    $this->journal->begin('upgrade', 1000);
    $file = $this->directory->path(OperationJournal::CURRENT);
    $json = json_decode((string) file_get_contents($file), true);
    file_put_contents($file, json_encode([...$json, 'schema_version' => '1.4', 'child' => ['container' => 'x']]));

    $this->journal->startPhase('pull');

    $rewritten = json_decode((string) file_get_contents($file), true);
    expect($rewritten['child'])->toBe(['container' => 'x'])
        ->and($rewritten['schema_version'])->toBe('1.4');
});

it('refuses a journal from a newer major version (exit 7)', function (): void {
    $this->directory->write(OperationJournal::CURRENT, json_encode(['schema_version' => '2.0', 'operation' => 'upgrade']));

    expect(fn () => $this->journal->current())->toThrow(IncompatibleSchema::class);
});

it('refuses a corrupt journal instead of guessing', function (): void {
    $this->directory->write(OperationJournal::CURRENT, json_encode(['schema_version' => '1.0', 'operation' => '']));

    expect(fn () => $this->journal->current())->toThrow(InvalidState::class);
});

it('fails loudly when a phase is recorded without an open operation', function (): void {
    expect(fn () => $this->journal->startPhase('pull'))->toThrow(InvalidState::class);
});
