<?php

declare(strict_types=1);

namespace App\Domain\Instance\Restore;

use App\Domain\Instance\Backup\KeyFingerprint;
use App\Domain\Instance\Backup\SafetyBackup;
use App\Domain\Instance\Compose\Services\AppRoles;
use App\Domain\Instance\Compose\Services\BackupService;
use App\Domain\Instance\Docker\ComposeProject;
use App\Domain\Instance\Docker\DockerGateway;
use App\Domain\Instance\Docker\DockerResource;
use App\Domain\Instance\InstanceException;
use App\Domain\Instance\Operations\InstanceLock;
use App\Domain\Instance\Operations\LockHandle;
use App\Domain\Instance\Operations\LockRecord;
use App\Domain\Instance\Operations\OperationRun;
use App\Domain\Instance\Output\CommandResult;
use App\Domain\Instance\Output\ExitCode;
use App\Domain\Instance\State\InstanceDirectory;
use App\Domain\Instance\State\InstanceState;
use App\Domain\Instance\State\RuntimeWriter;
use Throwable;

/**
 * The ways back of a restore (plan U17, KTD18, ADR 0085):
 *
 * - `abort()`: a failure before the swap. The scratch database, the restricted role, the fresh volume and the scratch
 *   directory go, the previous stack starts again behind the gate and the gate goes down last: exit 5, the old instance
 *   runs.
 * - `returnToPrevious()`: a failure from the swap on, before the gate went down (or an interrupted swap found by a
 *   resumed run). Journaled as the phase `return` (a resumed run or `--abandon` only ever finishes it): the put-aside
 *   database becomes the live one again (renamed back alone when the live name is free), the state names the previous
 *   storage volume, Valkey is flushed, the app must pass the smoke test behind the gate, everything starts, then the
 *   gate goes down: exit 5. When that fails, every frontend stops, the gate stays up and the result names what is where:
 *   exit 8, and the next run continues the return.
 *
 * Both seal the safety backup when encryption is on (exit 8 keeps it plain: a manual restore of it needs no identity).
 */
final readonly class RestoreRecovery
{
    /** Journal note: the previous database is back under the live name (a resumed return must not rename again). */
    public const string DATABASE_BACK = 'returned_database';

    /** Journal note: renaming the previous database back began. */
    public const string DATABASE_RETURNING = 'returning_database';

    public function __construct(
        private InstanceDirectory $directory,
        private DockerGateway $docker,
        private RestoreDatabases $databases,
        private RestoreStorage $storage,
        private RestoreStack $stack,
        private RestoreSource $source,
        private SafetyBackup $safety,
        private InstanceLock $lock,
    ) {}

    public function abort(InstanceState $state, ComposeProject $project, RestoreNames $names, Throwable $cause, bool $quiesced, ?string $safetyBackup = null): CommandResult
    {
        $left = $this->dropScratch($project, $names);
        if ($quiesced) {
            try {
                $this->stack->reopen($state, $project);
            } catch (Throwable $e) {
                return $this->stopped($project, $names, $cause, $e, $safetyBackup);
            }
        }

        return $this->sealed($this->failed($cause, 'The restore failed before the swap; the instance runs as before.', ['left_behind' => $left]), $safetyBackup);
    }

    /**
     * @param  InstanceState  $previous  the state before the swap (its storage volume, its webhook setting)
     * @param  list<string>  $addedKeys  fingerprints of APP keys the swap added
     * @param  bool  $swapped  the journal says the swap completed: the put-aside database and the previous volume must exist
     */
    public function returnToPrevious(OperationRun $run, InstanceState $previous, ComposeProject $project, RestoreNames $names, Throwable $cause, array $addedKeys, ?string $safetyBackup, bool $swapped = false): CommandResult
    {
        try {
            $run->phase('return', function () use ($run, $previous, $project, $names, $addedKeys, $swapped): array {
                $this->swapBack($run, $project, $names, $swapped);
                $secrets = $this->stack->secrets();
                $kept = array_values(array_filter($secrets->previousKeys, static fn (string $key): bool => ! in_array(KeyFingerprint::of($key), $addedKeys, true)));
                $this->stack->apply($previous, $secrets->withPreviousKeys($kept));
                $this->stack->flushValkey($project);
                $token = new RuntimeWriter($this->directory)->raiseGate($previous, 'restore');
                $this->stack->startAndVerify($previous, $project, $token);
                $this->stack->reopen($previous, $project);

                return ['returned' => true];
            }); // a completed return is never repeated: the previous instance took requests again
        } catch (Throwable $e) {
            return $this->stopped($project, $names, $cause, $e, $safetyBackup);
        }
        $left = $this->dropScratch($project, $names);

        return $this->sealed($this->failed($cause, 'The restored instance did not pass its verification; the previous database and storage volume are back.', ['left_behind' => $left, 'returned_to_previous' => true]), $safetyBackup);
    }

    /** @return list<string> what could not be removed (reported, removed by `fsa restore --abandon` or by hand) */
    public function dropScratch(ComposeProject $project, RestoreNames $names, bool $databases = true): array
    {
        $left = [];
        if ($databases) {
            try {
                $this->databases->drop($project, $names->scratch, $names->role);
            } catch (Throwable) {
                $left[] = "database {$names->scratch}";
            }
            if ($names->freshVolume !== $names->currentVolume && $this->volumeExists($names->freshVolume) && ! $this->storage->remove($names->freshVolume)) {
                $left[] = "volume {$names->freshVolume}";
            }
        }
        try {
            $this->source->discard($names->id);
        } catch (Throwable) {
            $left[] = 'directory backups/'.RestoreSource::SCRATCH_PREFIX.$names->id;
        }

        return $left;
    }

    /** Encrypts the safety backup to the recipients once the operation closed (R57); a failure is a warning. */
    public function sealed(CommandResult $result, ?string $safetyBackup): CommandResult
    {
        $lock = $this->lock->current();
        if ($safetyBackup === null || ! $lock instanceof LockRecord) {
            return $result;
        }
        try {
            $this->safety->seal($this->directory->state(), $safetyBackup, new LockHandle($lock));
        } catch (Throwable $e) {
            return $result->withWarning('safety_backup_not_sealed', "The safety backup {$safetyBackup} stays unencrypted (0600): ".$e->getMessage(), 'Encrypt or delete it by hand.');
        }

        return $result;
    }

    /**
     * Puts the previous database back under the live name, when the swap renamed it: swapped back when both names exist,
     * renamed back when the live name is free (a run killed between the two renames, review A8). Two journal notes make
     * this repeatable (review A2): `returning_database` before the first rename, `returned_database` after the last. A
     * resumed return whose rename began and whose put-aside database is gone finished renaming. A swap the journal says
     * completed whose put-aside database or previous volume is gone, with no return that renamed it, cannot be undone:
     * exit 8, never a silent mix of restored data and the previous volume.
     *
     * @throws InstanceException exit 8
     */
    private function swapBack(OperationRun $run, ComposeProject $project, RestoreNames $names, bool $swapped): void
    {
        if (! $this->volumeExists($names->currentVolume)) {
            throw new InstanceException("The previous storage volume {$names->currentVolume} is gone.", ExitCode::RollbackFailed, 'previous_volume_missing');
        }
        if ($this->noted($run, self::DATABASE_BACK)) {
            return; // an earlier attempt of this return renamed it back already
        }
        $databases = $this->databases->list($project, ''); // every database: the names may be shortened copies of the live one
        $aside = in_array($names->aside, $databases, true);
        $live = in_array($names->live, $databases, true);
        if (! $aside && $swapped && (! $live || ! $this->noted($run, self::DATABASE_RETURNING))) {
            throw new InstanceException("The previous database {$names->aside} is gone.", ExitCode::RollbackFailed, 'previous_database_missing');
        }
        if ($aside) {
            $run->note(self::DATABASE_RETURNING, ['live' => $names->live]);
            if ($live) {
                $this->databases->swap($project, $names->aside, $names->live, $names->scratch);
            } else {
                $this->databases->rename($project, $names->aside, $names->live);
            }
        }
        $run->note(self::DATABASE_BACK, ['live' => $names->live]);
    }

    private function noted(OperationRun $run, string $type): bool
    {
        return array_any($run->record()->events(), static fn (array $event): bool => $event['type'] === $type);
    }

    private function volumeExists(string $volume): bool
    {
        return array_any($this->docker->volumes([]), static fn (DockerResource $resource): bool => $resource->name === $volume);
    }

    private function stopped(ComposeProject $project, RestoreNames $names, Throwable $cause, Throwable $failure, ?string $safetyBackup): CommandResult
    {
        try {
            $this->docker->composeStop($project, [AppRoles::APP, AppRoles::QUEUE, AppRoles::SCHEDULER, BackupService::NAME]);
        } catch (Throwable) {
            // already stopped or Docker unreachable: the result says what is where either way
        }

        return CommandResult::failed('restore', ExitCode::RollbackFailed, 'restore_rollback_failed',
            'The restore failed ('.$cause->getMessage().') and returning to the previous instance failed too ('.$failure->getMessage().'); the instance is stopped behind its gate.',
            "The previous database is {$names->aside} (or {$names->live}), the restored one {$names->live} (or {$names->scratch}); the previous storage volume is {$names->currentVolume}, the restored one {$names->freshVolume}. Run `fsa restore --abandon` (or the same `fsa restore` again) to continue returning to the previous instance, or restore the safety backup".($safetyBackup === null ? '' : " {$safetyBackup}").' with `fsa restore`.',
            false, ['names' => $names->toArray(), 'safety_backup' => $safetyBackup], changed: true);
    }

    /** @param  array<string, mixed>  $data */
    private function failed(Throwable $cause, string $summary, array $data): CommandResult
    {
        $code = $cause instanceof InstanceException ? $cause->errorCode : 'restore_failed';
        $hint = $cause instanceof InstanceException && $cause->hint !== null ? $cause->hint : 'Read `fsa logs app` and the output, fix the cause, then run `fsa restore` again.';
        $details = $cause instanceof InstanceException ? $cause->data : [];

        return CommandResult::failed('restore', ExitCode::RolledBack, $code, $summary.' Cause: '.$cause->getMessage(), $hint, true, [...$details, ...$data], changed: true);
    }
}
