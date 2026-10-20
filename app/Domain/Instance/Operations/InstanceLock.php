<?php

declare(strict_types=1);

namespace App\Domain\Instance\Operations;

use App\Domain\Instance\Docker\ContainerInfo;
use App\Domain\Instance\Docker\DockerGateway;
use App\Domain\Instance\State\InstanceDirectory;
use App\Domain\Instance\State\SchemaVersion;
use Carbon\CarbonImmutable;

/**
 * The instance lock (R54, KTD4): a directory created atomically with mkdir (no flock over virtiofs), holding the
 * owner record (owner, operation, holder container ID and start time) and a heartbeat.
 *
 * A lock is stale only when Docker reports its holder container gone, stopped, or restarted since the lock was
 * taken; heartbeat age alone never is. The backup service cannot ask Docker for its start time (it has no socket) and
 * records its init process's start instead (LockRecord::PROCESS_START_PREFIX): its lock is stale only once the
 * container is gone or stopped, and the daemon itself releases a lock it took before a restart (ADR 0084). Breaking a stale lock happens under a short-lived takeover marker and
 * re-checks the lock ID, so two processes can never both take it over, nor remove a fresh lock by mistake.
 */
final readonly class InstanceLock
{
    public const string DIRECTORY = InstanceDirectory::LOCKS_DIR.'/instance.lock';

    public const string TAKEOVER_MARKER = InstanceDirectory::LOCKS_DIR.'/takeover.lock';

    private const string RECORD = self::DIRECTORY.'/owner.json';

    private const string HEARTBEAT = self::DIRECTORY.'/heartbeat';

    /** A lock directory without owner record counts as held this long (its creator writes it within ms). */
    private const int INCOMPLETE_GRACE_SECONDS = 30;

    /** A takeover marker older than this was left by a crashed process. */
    private const int TAKEOVER_GRACE_SECONDS = 60;

    private const int ATTEMPTS = 3;

    public function __construct(
        private InstanceDirectory $directory,
        private DockerGateway $docker,
    ) {}

    /**
     * @param  bool  $mayTakeOver  false for the backup daemon, which never takes over anyone else's lock
     *
     * @throws LockHeld
     */
    public function acquire(LockHolder $holder, string $operation, bool $mayTakeOver = true): LockHandle
    {
        $replaced = null;
        for ($attempt = 1; $attempt <= self::ATTEMPTS; $attempt++) {
            $record = $this->tryCreate($holder, $operation);
            if ($record instanceof LockRecord) {
                return new LockHandle($record, $replaced);
            }
            $current = $this->current();
            if (! $current instanceof LockRecord) {
                continue;
            }
            if (! $mayTakeOver || ! $this->isStale($current) || ! $this->enterTakeover()) {
                throw new LockHeld($current);
            }

            try {
                $replaced = $this->discardIfUnchanged($current) ?? $replaced;
            } finally {
                $this->leaveTakeover();
            }
        }

        throw new LockHeld($this->current() ?? LockRecord::incomplete(null));
    }

    /** Removes the lock if it is still this handle's (not taken over meanwhile). */
    public function release(LockHandle $handle): bool
    {
        if ($this->current()?->lockId !== $handle->record->lockId) {
            return false;
        }
        $this->discard();

        return true;
    }

    /**
     * Backup daemon start-up: releases a lock that this very container took before it was restarted (same
     * container ID, earlier start time). Any other lock is left alone.
     */
    public function releaseOwnStale(LockHolder $holder): ?LockRecord
    {
        $current = $this->current();
        if (! $current instanceof LockRecord || $holder->containerId === null || $current->containerId !== $holder->containerId
            || $current->containerStartedAt === $holder->containerStartedAt || ! $this->enterTakeover()) {
            return null;
        }

        try {
            return $this->discardIfUnchanged($current);
        } finally {
            $this->leaveTakeover();
        }
    }

    /** Records liveness for `fsa status`; written by the holder only. */
    public function heartbeat(LockHandle $handle): void
    {
        if ($this->current()?->lockId === $handle->record->lockId) {
            $this->directory->write(self::HEARTBEAT, $this->now()->toIso8601ZuluString()."\n");
        }
    }

    public function current(): ?LockRecord
    {
        $files = $this->directory->files();
        if (! $files->exists(self::DIRECTORY)) {
            return null;
        }
        $json = $files->read(self::RECORD);
        if ($json === null) {
            $since = $files->modifiedAt(self::DIRECTORY);

            return $since === null ? null : LockRecord::incomplete(CarbonImmutable::createFromTimestamp($since, 'UTC')->toIso8601ZuluString());
        }
        $data = InstanceDirectory::decode($json, 'instance lock');
        SchemaVersion::parse($data['schema_version'] ?? null, 'instance lock')
            ->assertReadableBy(SchemaVersion::of(LockRecord::SCHEMA_VERSION), 'instance lock');
        $heartbeat = $files->read(self::HEARTBEAT);

        return LockRecord::fromArray($data, $heartbeat === null ? null : trim($heartbeat));
    }

    public function isStale(?LockRecord $record): bool
    {
        if (! $record instanceof LockRecord) {
            return false;
        }
        if (! $record->complete) {
            return $record->acquiredAt !== null
                && $this->now()->getTimestamp() - CarbonImmutable::parse($record->acquiredAt)->getTimestamp() > self::INCOMPLETE_GRACE_SECONDS;
        }
        if ($record->containerId === null) {
            return false;
        }
        $container = $this->docker->inspectContainer($record->containerId);
        if (! $container instanceof ContainerInfo || ! $container->running()) {
            return true;
        }

        return ! $record->startUnknownToDocker() && $container->startedAt !== $record->containerStartedAt;
    }

    private function tryCreate(LockHolder $holder, string $operation): ?LockRecord
    {
        if (! $this->directory->files()->createExclusive(self::DIRECTORY)) {
            return null;
        }
        $record = LockRecord::forHolder($holder, $operation, $this->now()->toIso8601ZuluString());
        $this->directory->write(self::RECORD, json_encode($record->toArray(), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n");

        return $record;
    }

    /** Under the takeover marker: discard the lock only if it is still the one judged stale. */
    private function discardIfUnchanged(LockRecord $expected): ?LockRecord
    {
        $current = $this->current();
        if (! $current instanceof LockRecord || $current->lockId !== $expected->lockId || $current->complete !== $expected->complete) {
            return null;
        }
        $this->discard();

        return $current;
    }

    private function discard(): void
    {
        $files = $this->directory->files();
        $tombstone = InstanceDirectory::LOCKS_DIR.'/.released-'.bin2hex(random_bytes(6));
        if ($files->rename(self::DIRECTORY, $tombstone)) {
            $files->delete($tombstone);
        }
    }

    private function enterTakeover(): bool
    {
        $files = $this->directory->files();
        if ($files->createExclusive(self::TAKEOVER_MARKER)) {
            return true;
        }
        $since = $files->modifiedAt(self::TAKEOVER_MARKER);
        if ($since !== null && $this->now()->getTimestamp() - $since <= self::TAKEOVER_GRACE_SECONDS) {
            return false;
        }
        $files->delete(self::TAKEOVER_MARKER);

        return $files->createExclusive(self::TAKEOVER_MARKER);
    }

    private function leaveTakeover(): void
    {
        $this->directory->files()->delete(self::TAKEOVER_MARKER);
    }

    private function now(): CarbonImmutable
    {
        return CarbonImmutable::now('UTC');
    }
}
