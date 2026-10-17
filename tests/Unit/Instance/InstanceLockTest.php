<?php

declare(strict_types=1);

use App\Domain\Instance\Operations\InstanceLock;
use App\Domain\Instance\Operations\LockHeld;
use App\Domain\Instance\Operations\LockHolder;
use App\Domain\Instance\Output\ExitCode;
use App\Domain\Instance\State\InstanceDirectory;
use Carbon\CarbonImmutable;
use Tests\Support\Instance\RecordingDockerGateway;
use Tests\Support\Instance\TemporaryInstance;

const LOCK_STARTED = '2026-10-17T10:00:00.000000001Z';

beforeEach(function (): void {
    $this->directory = TemporaryInstance::directory();
    $this->docker = new RecordingDockerGateway;
    $this->lock = new InstanceLock($this->directory, $this->docker);
    $this->holderA = TemporaryInstance::holder('a', LOCK_STARTED);
    $this->holderB = TemporaryInstance::holder('b', LOCK_STARTED);
    $this->docker->withRunningContainer($this->holderA->containerId, LOCK_STARTED)
        ->withRunningContainer($this->holderB->containerId, LOCK_STARTED);
});

afterEach(function (): void {
    TemporaryInstance::cleanup();
    CarbonImmutable::setTestNow();
});

it('creates the lock directory atomically and records owner, operation, holder container and start time', function (): void {
    $handle = $this->lock->acquire($this->holderA, 'upgrade');

    $record = $this->lock->current();
    expect($handle->replaced)->toBeNull()
        ->and(is_dir($this->directory->path(InstanceLock::DIRECTORY)))->toBeTrue()
        ->and(fileperms($this->directory->path(InstanceLock::DIRECTORY)) & 0777)->toBe(0700)
        ->and($record?->lockId)->toBe($handle->record->lockId)
        ->and($record?->owner)->toBe('fsa')
        ->and($record?->operation)->toBe('upgrade')
        ->and($record?->containerId)->toBe(str_repeat('a', 64))
        ->and($record?->containerStartedAt)->toBe(LOCK_STARTED)
        ->and($record?->hostUid)->toBe(501);
});

it('refuses a second lock while one is held and names the holder', function (): void {
    $this->lock->acquire($this->holderA, 'upgrade');

    try {
        $this->lock->acquire($this->holderB, 'backup');
        $this->fail('The second acquire should have failed.');
    } catch (LockHeld $e) {
        expect($e->exitCode)->toBe(ExitCode::AbortedUnchanged)
            ->and($e->retryable)->toBeTrue()
            ->and($e->getMessage())->toContain('upgrade')->toContain('aaaaaaaaaaaa')
            ->and($e->data['holder']['operation'])->toBe('upgrade')
            ->and($e->data['holder']['container_id'])->toBe(str_repeat('a', 64));
    }
    expect($this->lock->current()?->operation)->toBe('upgrade');
});

it('takes over a lock whose holder container is gone', function (): void {
    $first = $this->lock->acquire($this->holderA, 'upgrade');
    $this->docker->forgetContainer($this->holderA->containerId);

    $second = $this->lock->acquire($this->holderB, 'rollback');

    expect($second->replaced?->lockId)->toBe($first->record->lockId)
        ->and($second->replaced?->operation)->toBe('upgrade')
        ->and($this->lock->current()?->operation)->toBe('rollback')
        ->and($this->lock->current()?->containerId)->toBe(str_repeat('b', 64));
});

it('takes over a lock whose holder container restarted since the lock was taken', function (): void {
    $this->lock->acquire($this->holderA, 'upgrade');
    $this->docker->restartContainer($this->holderA->containerId, '2026-10-17T11:00:00.5Z');

    $second = $this->lock->acquire($this->holderB, 'upgrade');

    expect($second->replaced?->containerId)->toBe(str_repeat('a', 64));
});

it('takes over a lock whose holder container has stopped', function (): void {
    $this->lock->acquire($this->holderA, 'install');
    $this->docker->stopContainer($this->holderA->containerId);

    expect($this->lock->acquire($this->holderB, 'install')->replaced)->not->toBeNull();
});

it('never treats an old heartbeat alone as stale while the holder container runs', function (): void {
    CarbonImmutable::setTestNow('2026-10-17 10:00:00');
    $handle = $this->lock->acquire($this->holderA, 'restore');
    $this->lock->heartbeat($handle);
    CarbonImmutable::setTestNow('2026-10-18 10:00:00');

    expect($this->lock->current()?->heartbeatAt)->toBe('2026-10-17T10:00:00Z')
        ->and($this->lock->isStale($this->lock->current()))->toBeFalse()
        ->and(fn () => $this->lock->acquire($this->holderB, 'upgrade'))->toThrow(LockHeld::class);
});

it('lets the backup daemon never take over anyone else\'s lock, even a stale one', function (): void {
    $this->lock->acquire($this->holderA, 'upgrade');
    $this->docker->forgetContainer($this->holderA->containerId);
    $daemon = TemporaryInstance::holder('d', LOCK_STARTED, 'backup-daemon');

    expect(fn () => $this->lock->acquire($daemon, 'backup', mayTakeOver: false))->toThrow(LockHeld::class)
        ->and($this->lock->current()?->operation)->toBe('upgrade');
});

it('lets a backup daemon restarted mid-run release its own lock from the earlier start', function (): void {
    $earlier = TemporaryInstance::holder('d', LOCK_STARTED, 'backup-daemon');
    $this->docker->withRunningContainer($earlier->containerId, LOCK_STARTED);
    $this->lock->acquire($earlier, 'backup');
    $this->docker->restartContainer($earlier->containerId, '2026-10-17T12:00:00.1Z');
    $now = TemporaryInstance::holder('d', '2026-10-17T12:00:00.1Z', 'backup-daemon');

    $released = $this->lock->releaseOwnStale($now);

    expect($released?->containerStartedAt)->toBe(LOCK_STARTED)
        ->and($this->lock->current())->toBeNull()
        ->and($this->lock->acquire($now, 'backup', mayTakeOver: false)->replaced)->toBeNull();
});

it('does not release another container\'s lock or its own lock from the current start', function (): void {
    $this->lock->acquire($this->holderA, 'upgrade');
    $daemon = TemporaryInstance::holder('d', LOCK_STARTED, 'backup-daemon');

    expect($this->lock->releaseOwnStale($daemon))->toBeNull()
        ->and($this->lock->releaseOwnStale($this->holderA))->toBeNull()
        ->and($this->lock->current()?->operation)->toBe('upgrade');
});

it('releases only its own lock, not one that was taken over meanwhile', function (): void {
    $first = $this->lock->acquire($this->holderA, 'upgrade');
    $this->docker->forgetContainer($this->holderA->containerId);
    $this->lock->acquire($this->holderB, 'rollback');

    expect($this->lock->release($first))->toBeFalse()
        ->and($this->lock->current()?->operation)->toBe('rollback');
});

it('releases its lock so the next operation can take it', function (): void {
    $handle = $this->lock->acquire($this->holderA, 'install');

    expect($this->lock->release($handle))->toBeTrue()
        ->and($this->lock->current())->toBeNull()
        ->and($this->directory->files()->list(InstanceDirectory::LOCKS_DIR))->toBe([])
        ->and($this->lock->acquire($this->holderB, 'status')->replaced)->toBeNull();
});

it('never declares a lock stale when the holder ran outside a container', function (): void {
    $this->lock->acquire(new LockHolder('fsa', null, null, 77, 'dev-host', 501), 'install');

    expect($this->lock->isStale($this->lock->current()))->toBeFalse()
        ->and(fn () => $this->lock->acquire($this->holderB, 'install'))->toThrow(LockHeld::class);
});

it('treats a lock directory without an owner record as held, and as stale once the grace period passed', function (): void {
    CarbonImmutable::setTestNow('2026-10-17 10:00:00');
    $this->directory->files()->createExclusive(InstanceLock::DIRECTORY);
    touch($this->directory->path(InstanceLock::DIRECTORY), CarbonImmutable::now()->getTimestamp());

    expect(fn () => $this->lock->acquire($this->holderA, 'install'))->toThrow(LockHeld::class);

    CarbonImmutable::setTestNow('2026-10-17 10:05:00');
    $handle = $this->lock->acquire($this->holderA, 'install');

    expect($handle->replaced?->complete)->toBeFalse()
        ->and($this->lock->current()?->operation)->toBe('install');
});

it('clears an abandoned takeover marker so stale locks can still be taken over', function (): void {
    CarbonImmutable::setTestNow('2026-10-17 10:00:00');
    $this->lock->acquire($this->holderA, 'upgrade');
    $this->docker->forgetContainer($this->holderA->containerId);
    $this->directory->files()->createExclusive(InstanceLock::TAKEOVER_MARKER);
    touch($this->directory->path(InstanceLock::TAKEOVER_MARKER), CarbonImmutable::now()->subMinutes(5)->getTimestamp());

    expect($this->lock->acquire($this->holderB, 'rollback')->replaced?->operation)->toBe('upgrade')
        ->and($this->directory->exists(InstanceLock::TAKEOVER_MARKER))->toBeFalse();
});

it('gives up when another process is taking the stale lock over right now', function (): void {
    CarbonImmutable::setTestNow('2026-10-17 10:00:00');
    $this->lock->acquire($this->holderA, 'upgrade');
    $this->docker->forgetContainer($this->holderA->containerId);
    $this->directory->files()->createExclusive(InstanceLock::TAKEOVER_MARKER);
    touch($this->directory->path(InstanceLock::TAKEOVER_MARKER), CarbonImmutable::now()->getTimestamp());

    expect(fn () => $this->lock->acquire($this->holderB, 'rollback'))->toThrow(LockHeld::class);
});

it('writes no heartbeat for a lock it no longer holds', function (): void {
    $first = $this->lock->acquire($this->holderA, 'upgrade');
    $this->docker->forgetContainer($this->holderA->containerId);
    $this->lock->acquire($this->holderB, 'rollback');

    $this->lock->heartbeat($first);

    expect($this->lock->current()?->heartbeatAt)->toBeNull();
});
