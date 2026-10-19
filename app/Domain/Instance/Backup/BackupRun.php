<?php

declare(strict_types=1);

namespace App\Domain\Instance\Backup;

use App\Domain\Instance\Operations\InstanceLock;
use App\Domain\Instance\Operations\LockHandle;
use App\Domain\Instance\Operations\LockHeld;
use App\Domain\Instance\Operations\LockHolder;
use App\Domain\Instance\Operations\LockRecord;
use Carbon\CarbonImmutable;
use SensitiveParameter;
use Throwable;

/**
 * One backup run in the backup service (KTD4, KTD15, R57, ADR 0084), scheduled or as a one-off of `fsa backup` and the
 * safety-backup entry point:
 *
 * - The lock: a scheduled run takes the instance lock itself and never takes over anyone else's (an operation holding
 *   it makes the run a skip); a one-off runs under the lock its fsa parent holds, named by its ID, and refuses
 *   otherwise.
 * - Under the lock: leftovers of an interrupted run go, the run must fit on the disk (a scheduled run is skipped,
 *   another fails), the writer writes the set, and after a successful scheduled run retention prunes old scheduled
 *   backups.
 * - Every run is recorded (ledger and `backup_runs`); the daemon records only the first skip of a slot.
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
     * @param  string|null  $parentLock  the lock ID of the fsa command this one-off runs for; null for a scheduled run
     * @return array<string, mixed> the recorded run (status succeeded, failed or skipped)
     */
    public function run(BackupName $name, LockHolder $holder, ?string $parentLock = null, #[SensitiveParameter] ?string $secrets = null, bool $recordSkip = true): array
    {
        $started = CarbonImmutable::now('UTC');
        $base = ['kind' => $name->kind, 'started_at' => $started->toIso8601ZuluString(), 'encrypted' => $this->config->encrypts(), 'backup' => null];

        try {
            $handle = $this->takeLock($holder, $parentLock);
        } catch (LockHeld $held) {
            $run = [...$base, 'status' => 'skipped', 'reason' => 'lock_held', 'message' => 'An operation holds the instance lock: '.$held->holder->describe().'.', 'finished_at' => $started->toIso8601ZuluString()];

            return $recordSkip ? $this->recorder->record($run) : [...$run, 'recorded' => false];
        } catch (BackupFailed $refused) {
            return $this->recorder->record([...$base, 'status' => 'failed', 'reason' => $refused->reason, 'message' => $refused->getMessage(), 'finished_at' => $started->toIso8601ZuluString()]);
        }

        try {
            return $this->recorder->record([...$base, ...$this->underLock($name, $secrets), 'finished_at' => CarbonImmutable::now('UTC')->toIso8601ZuluString()]);
        } finally {
            if ($handle instanceof LockHandle) {
                $this->lock->release($handle);
            }
        }
    }

    /** @return array<string, mixed> */
    private function underLock(BackupName $name, ?string $secrets): array
    {
        $this->store->cleanPartials();
        $free = $this->store->freeBytes();
        if ($free !== null && ! BackupRetention::fits($free, BackupRetention::projectedBytes($this->store->entries()), $this->config->minFreeMib)) {
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
        $pruned = $name->isScheduled() ? $this->prune() : [];

        return [
            'status' => 'succeeded', 'backup' => $result->name->name(), 'bytes' => $result->bytes, 'encrypted' => $result->encrypted,
            'missing_files' => $result->missingCount, 'free_bytes' => $this->store->freeBytes(), 'pruned' => $pruned,
            'result' => $result->toArray(),
        ];
    }

    /** @return list<string> the scheduled backups retention removed */
    private function prune(): array
    {
        $pruned = [];
        foreach (BackupRetention::prune($this->store->entries(), $this->config->retention) as $entry) {
            $this->store->delete($entry);
            $pruned[] = $entry->name->name();
        }

        return $pruned;
    }

    /** @throws LockHeld|BackupFailed */
    private function takeLock(LockHolder $holder, ?string $parentLock): ?LockHandle
    {
        if ($parentLock === null) {
            $handle = $this->lock->acquire($holder, self::OPERATION, mayTakeOver: false);
            $this->lock->heartbeat($handle);

            return $handle;
        }
        $current = $this->lock->current();
        if (! $current instanceof LockRecord || ! hash_equals($current->lockId, $parentLock)) {
            throw new BackupFailed('lock_mismatch', 'The fsa command that started this backup no longer holds the instance lock.');
        }

        return null;
    }
}
