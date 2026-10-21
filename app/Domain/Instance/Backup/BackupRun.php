<?php

declare(strict_types=1);

namespace App\Domain\Instance\Backup;

use App\Domain\Instance\Operations\InstanceLock;
use App\Domain\Instance\Operations\LockHandle;
use App\Domain\Instance\Operations\LockHeld;
use App\Domain\Instance\Operations\LockHolder;
use App\Domain\Instance\Operations\LockRecord;
use App\Domain\Platform\Instance\BackupAlerts;
use Carbon\CarbonImmutable;
use SensitiveParameter;
use Throwable;

/**
 * One backup run in the backup service (KTD4, KTD15, R57, ADR 0084), scheduled or as a one-off of `fieldset backup` and the
 * safety-backup entry point:
 *
 * - The lock: a scheduled run takes the instance lock itself and never takes over anyone else's (an operation holding
 *   it makes the run a skip); a one-off runs under the lock its fieldset parent holds, named by its ID, and refuses
 *   otherwise.
 * - Under the lock: leftovers of an interrupted run go, the run must fit on the disk (a scheduled run is skipped,
 *   another fails), the writer writes the set, and after a successful scheduled run retention prunes old scheduled
 *   backups, except those that may hold referenced files the new one misses (M12 gate D5, BackupRetention::held: a
 *   storage volume that lost files must not cost the backups that still hold them, however many runs follow;
 *   `retention_held` names the ones retention would have pruned).
 * - Every run is recorded (ledger and `backup_runs`); of a series of lock skips the daemon records one an hour.
 */
final readonly class BackupRun
{
    public const string OPERATION = 'backup';

    public function __construct(
        private InstanceLock $lock,
        private BackupStore $store,
        private BackupWriter $writer,
        private BackupRunRecorder $recorder,
        private BackupConfig $config,
    ) {}

    /**
     * @param  string|null  $parentLock  the lock ID of the fieldset command this one-off runs for; null for a scheduled run
     * @return array<string, mixed> the recorded run (status succeeded, failed or skipped)
     */
    public function run(BackupName $name, LockHolder $holder, ?string $parentLock = null, #[SensitiveParameter] ?string $secrets = null, bool $recordSkip = true): array
    {
        $started = CarbonImmutable::now('UTC');
        $base = ['kind' => $name->kind, 'started_at' => $started->toIso8601ZuluString(), 'encrypted' => $this->writer->encrypts($name), 'backup' => null];

        try {
            $handle = $this->takeLock($holder, $parentLock);
        } catch (LockHeld $held) {
            $run = [...$base, 'status' => 'skipped', 'reason' => 'lock_held', 'message' => 'An operation holds the instance lock: '.$held->holder->describe().'.', 'finished_at' => $started->toIso8601ZuluString()];

            return $recordSkip ? $this->recorder->record($run) : [...$run, 'recorded' => false];
        } catch (Throwable $refused) {
            $reason = $refused instanceof BackupFailed ? $refused->reason : 'lock_failed';

            return $this->recorder->record([...$base, 'status' => 'failed', 'reason' => $reason, 'message' => $refused->getMessage(), 'finished_at' => $started->toIso8601ZuluString()]);
        }

        try {
            $outcome = $this->underLock($name, $secrets);
        } catch (Throwable $e) {
            $outcome = ['status' => 'failed', 'reason' => 'internal_error', 'message' => $e->getMessage()];
        } finally {
            if ($handle instanceof LockHandle) {
                $this->lock->release($handle);
            }
        }

        return $this->recorder->record([...$base, ...$outcome, 'finished_at' => CarbonImmutable::now('UTC')->toIso8601ZuluString()]);
    }

    /** @return array<string, mixed> */
    private function underLock(BackupName $name, ?string $secrets): array
    {
        $this->store->cleanPartials();
        $free = $this->store->freeBytes();
        $entries = $this->store->entries();
        $projected = BackupRetention::projectedBytes($entries, $this->writer->encrypts($name), $entries === [] ? $this->writer->estimateBytes() : 0);
        if ($free !== null && ! BackupRetention::fits($free, $projected, $this->config->minFreeMib)) {
            $message = 'Not enough free space: '.intdiv($free, 1048576).' MiB free, the backup would leave less than '.$this->config->minFreeMib.' MiB.';

            return ['status' => $name->isScheduled() ? 'skipped' : 'failed', 'reason' => 'low_disk_space', 'message' => $message, 'free_bytes' => $free];
        }

        try {
            $result = $this->writer->write($name, $secrets);
        } catch (BackupFailed $failed) {
            return ['status' => 'failed', 'reason' => $failed->reason, 'message' => $failed->getMessage(), 'free_bytes' => $free];
        } catch (Throwable $e) {
            return ['status' => 'failed', 'reason' => 'internal_error', 'message' => $e->getMessage(), 'free_bytes' => $free];
        }
        [$pruned, $held] = $name->isScheduled() ? $this->prune($result) : [[], null];

        return [
            'status' => 'succeeded', 'backup' => $result->name->name(), 'bytes' => $result->bytes, 'encrypted' => $result->encrypted,
            'missing_files' => $result->missingCount, 'free_bytes' => $this->store->freeBytes(), 'pruned' => $pruned,
            ...($held === null ? [] : ['retention_held' => $held]), 'result' => $result->toArray(),
        ];
    }

    /**
     * The missing_files each backup's run recorded (the ledger's newest success per backup; null without a count).
     *
     * @return array<string, int|null>
     */
    private function missingCounts(): array
    {
        $counts = [];
        foreach ($this->recorder->recent(PHP_INT_MAX) as $run) {
            if (($run['status'] ?? null) === 'succeeded' && is_string($run['backup'] ?? null) && ! array_key_exists($run['backup'], $counts)) {
                $counts[$run['backup']] = is_int($run['missing_files'] ?? null) ? $run['missing_files'] : null;
            }
        }

        return $counts;
    }

    /**
     * The backup service's start (ADR 0084): what an interrupted run left in `backups/` goes (under the lock, when it is
     * free; else the next run removes it), and the start is recorded with the schedule (kind `service`), so `/platform`
     * can tell a service that never completes a backup.
     *
     * @return array<string, mixed> the recorded start
     */
    public function serviceStarted(LockHolder $holder): array
    {
        $removed = [];

        try {
            $handle = $this->lock->acquire($holder, self::OPERATION, mayTakeOver: false);

            try {
                $removed = $this->store->cleanPartials();
            } finally {
                $this->lock->release($handle);
            }
        } catch (Throwable) {
            // an operation holds the lock (or it cannot be read): the next run cleans up under it
        }
        $now = CarbonImmutable::now('UTC')->toIso8601ZuluString();

        return $this->recorder->record([
            'kind' => BackupAlerts::SERVICE, 'status' => 'started', 'started_at' => $now, 'finished_at' => $now, 'encrypted' => $this->config->encrypts(),
            'schedule' => $this->config->schedule, 'time_zone' => $this->config->timeZone, 'removed' => $removed,
        ]);
    }

    /**
     * Retention after a successful scheduled run, without the backups that may hold files the new one misses: the
     * scheduled backups removed, and those it held back (null when none).
     *
     * @return array{0: list<string>, 1: array{missing_files: int, backups: list<string>}|null}
     */
    private function prune(BackupResult $result): array
    {
        $entries = $this->store->entries();
        $hold = $result->missingCount === 0 ? [] : BackupRetention::held($entries, $result->name->name(), $result->missingCount, $this->missingCounts());
        $pruned = [];
        foreach (BackupRetention::prune($entries, $this->config->retention, $hold) as $entry) {
            if ($this->store->delete($entry)) {
                $pruned[] = $entry->name->name(); // only what is really gone
            }
        }
        $heldBack = array_values(array_filter(array_map(static fn (BackupEntry $entry): string => $entry->name->name(), BackupRetention::prune($entries, $this->config->retention)), static fn (string $name): bool => in_array($name, $hold, true)));

        return [$pruned, $heldBack === [] ? null : ['missing_files' => $result->missingCount, 'backups' => $heldBack]];
    }

    /** @throws LockHeld|BackupFailed|Throwable */
    private function takeLock(LockHolder $holder, ?string $parentLock): ?LockHandle
    {
        if ($parentLock === null) {
            $handle = $this->lock->acquire($holder, self::OPERATION, mayTakeOver: false);

            try {
                $this->lock->heartbeat($handle);
            } catch (Throwable) {
                // Best effort, as for every operation: the heartbeat only informs `fieldset status`; the lock is held either way.
            }

            return $handle;
        }
        $current = $this->lock->current();
        if (! $current instanceof LockRecord || ! hash_equals($current->lockId, $parentLock)) {
            throw new BackupFailed('lock_mismatch', 'The fieldset command that started this backup no longer holds the instance lock.');
        }

        return null;
    }
}
