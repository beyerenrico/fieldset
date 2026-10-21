<?php

declare(strict_types=1);

namespace App\Domain\Instance\Restore;

use App\Domain\Instance\Backup\KeyFingerprint;
use App\Domain\Instance\Backup\SafetyBackup;
use App\Domain\Instance\Docker\ComposeProject;
use App\Domain\Instance\Docker\DockerGateway;
use App\Domain\Instance\Docker\DockerResource;
use App\Domain\Instance\InstanceException;
use App\Domain\Instance\Operations\InstanceLock;
use App\Domain\Instance\Operations\JournalRecord;
use App\Domain\Instance\Operations\LockHandle;
use App\Domain\Instance\Operations\LockRecord;
use App\Domain\Instance\Operations\OperationRun;
use App\Domain\Instance\Output\CommandResult;
use App\Domain\Instance\Output\ExitCode;
use App\Domain\Instance\State\InstanceDirectory;
use App\Domain\Instance\State\InstanceState;
use App\Domain\Instance\State\RuntimeWriter;
use App\Domain\Instance\Upgrade\SafetySeal;
use Throwable;

/**
 * The ways back of a restore (plan U17, KTD18, ADR 0085):
 *
 * - `abort()`: a failure before the swap, journaled as the phase `abort` before anything is touched (third review M2:
 *   a run killed while going back is finished as an abort, never resumed as a restore over the writes the reopened
 *   instance took). The scratch database, the restricted role, the fresh volume and the scratch directory go, the
 *   unchanged app starts, the gate goes down once it is healthy and the other services start (one that does not is
 *   reported, L7): exit 5, the old instance runs.
 * - `returnToPrevious()`: a failure from the swap on, before the gate went down (or an interrupted swap found by a
 *   resumed run). Journaled as the phase `return` (a resumed run or `--abandon` only ever finishes it): every writer
 *   stops (M3), the put-aside database becomes the live one again (renamed back alone when the live name is free), the
 *   state names the previous storage volume, Valkey is flushed, the app must pass the smoke test behind the gate, the
 *   gate goes down and everything else starts: exit 5. When that fails the result is exit 8 (RestoreHalt).
 *
 * Both seal the safety backup when encryption is on (exit 8 keeps it plain: a manual restore of it needs no identity).
 */
final readonly class RestoreRecovery
{
    /** Journal note: the previous database is back under the live name (a resumed return must not rename again). */
    public const string DATABASE_BACK = 'returned_database';

    /** Journal note: renaming the previous database back began. */
    public const string DATABASE_RETURNING = 'returning_database';

    /** Journal note: the put-aside database or the previous volume is gone before any rename back (`--abandon` goes forward). */
    public const string NO_WAY_BACK = 'no_way_back';

    public function __construct(
        private InstanceDirectory $directory,
        private DockerGateway $docker,
        private RestoreDatabases $databases,
        private RestoreStorage $storage,
        private RestoreStack $stack,
        private RestoreSource $source,
        private SafetyBackup $safety,
        private InstanceLock $lock,
        private RestoreHalt $halt,
        private SafetySeal $seals,
    ) {}

    public function abort(OperationRun $run, InstanceState $state, ComposeProject $project, RestoreNames $names, Throwable $cause, bool $quiesced, ?string $safetyBackup = null): CommandResult
    {
        try {
            $closed = $this->goBack($run, $state, $project, $names, $quiesced, $cause);
        } catch (Throwable $e) {
            return $this->halt->stop($project, $names, $cause, $e, $safetyBackup, 'abort');
        }
        $result = $this->failed($cause, 'The restore failed before the swap; the instance runs as before.', ['left_behind' => $closed['left_behind']]);

        return $this->sealed(self::degraded($result, $closed['degraded']), $safetyBackup, $run->record());
    }

    /**
     * The journaled way back before the swap (phase `abort`; RestoreAbandon uses it too). A completed phase is not run
     * again: a run killed during the seal only seals.
     *
     * @return array{left_behind: list<string>, degraded: list<string>}
     */
    public function goBack(OperationRun $run, InstanceState $state, ComposeProject $project, RestoreNames $names, bool $quiesced, ?Throwable $cause = null): array
    {
        $data = $run->phase('abort', fn (): array => [
            'left_behind' => $names->id === '' ? [] : $this->dropScratch($project, $names),
            'degraded' => $quiesced ? $this->stack->reopenPrevious($state, $project) : [],
        ], $cause instanceof InstanceException ? ['code' => $cause->errorCode, 'message' => $cause->getMessage()] : ['code' => 'restore_failed', 'message' => $cause?->getMessage()]);

        return ['left_behind' => RestoreNames::strings($data['left_behind'] ?? null), 'degraded' => RestoreNames::strings($data['degraded'] ?? null)];
    }

    /** The failure an interrupted abort was going back from (its phase's start data). */
    public static function abortCause(OperationRun $run): InstanceException
    {
        $data = $run->record()->lastAttemptData('abort');

        return new InstanceException(
            is_string($data['message'] ?? null) ? $data['message'] : 'An earlier run of this restore stopped while going back.',
            ExitCode::RolledBack, is_string($data['code'] ?? null) ? $data['code'] : 'restore_interrupted',
        );
    }

    /**
     * @param  InstanceState  $previous  the state before the swap (its storage volume, its webhook setting)
     * @param  list<string>  $addedKeys  fingerprints of APP keys the swap added
     * @param  bool  $swapped  the journal says the swap completed: the put-aside database and the previous volume must exist
     */
    public function returnToPrevious(OperationRun $run, InstanceState $previous, ComposeProject $project, RestoreNames $names, Throwable $cause, array $addedKeys, ?string $safetyBackup, bool $swapped = false): CommandResult
    {
        try {
            $returned = $run->phase('return', function () use ($run, $previous, $project, $names, $addedKeys, $swapped): array {
                $this->docker->composeStop($project, RestoreHalt::WRITERS); // nothing writes while the databases are renamed back (M3)
                $this->swapBack($run, $project, $names, $swapped);
                $secrets = $this->stack->secrets();
                $kept = array_values(array_filter($secrets->previousKeys, static fn (string $key): bool => ! in_array(KeyFingerprint::of($key), $addedKeys, true)));
                $this->stack->apply($previous, $secrets->withPreviousKeys($kept));
                $this->stack->flushValkey($project);
                $token = new RuntimeWriter($this->directory)->raiseGate($previous, 'restore');
                $this->stack->startAndVerify($previous, $project, $token);

                return ['returned' => true, 'degraded' => $this->stack->reopenPrevious($previous, $project)];
            }); // a completed return is never repeated: the previous instance took requests again
        } catch (Throwable $e) {
            return $this->halt->stop($project, $names, $cause, $e, $safetyBackup, 'return');
        }
        $left = $this->dropScratch($project, $names);
        $result = $this->failed($cause, 'The restored instance did not pass its verification; the previous database and storage volume are back.', ['left_behind' => $left, 'returned_to_previous' => true]);

        return $this->sealed(self::degraded($result, RestoreNames::strings($returned['degraded'] ?? null)), $safetyBackup, $run->record());
    }

    /** @param  list<string>  $degraded */
    public static function degraded(CommandResult $result, array $degraded): CommandResult
    {
        return $degraded === [] ? $result : $result->withWarning('services_degraded', 'The previous instance takes requests, but these services did not start or are not healthy: '.implode(', ', $degraded).'.', 'Read `fieldset logs <service>`; `fieldset status` shows them.');
    }

    /** @return list<string> what could not be removed (reported, removed by `fieldset restore --abandon` or by hand) */
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
            $left[] = 'directory '.RestoreSource::scratchPath($names->id);
        }

        return $left;
    }

    /**
     * Encrypts the safety backup to the recipients once the operation closed (R57); a failure is a warning. With the journal
     * also every plaintext restore safety backup made since it began (M12 gate C3): one a killed run wrote before the journal
     * named it.
     */
    public function sealed(CommandResult $result, ?string $safetyBackup, ?JournalRecord $record = null): CommandResult
    {
        foreach ($record instanceof JournalRecord ? $this->seals->seal(array_values(array_diff($this->seals->since(RestoreOperation::OPERATION, $record->startedAt()), [$safetyBackup]))) : [] as [$code, $message, $hint]) {
            $result = $result->withWarning($code, $message, $hint);
        }
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
        $renaming = $this->noted($run, self::DATABASE_RETURNING);
        if (! $this->volumeExists($names->currentVolume)) {
            $this->noWayBack($run, ! $renaming, new InstanceException("The previous storage volume {$names->currentVolume} is gone.", ExitCode::RollbackFailed, 'previous_volume_missing'));
        }
        if ($this->noted($run, self::DATABASE_BACK)) {
            return; // an earlier attempt of this return renamed it back already
        }
        $databases = $this->databases->list($project, ''); // every database: the names may be shortened copies of the live one
        $aside = in_array($names->aside, $databases, true);
        $live = in_array($names->live, $databases, true);
        if (! $aside && $swapped && (! $live || ! $renaming)) {
            $this->noWayBack($run, ! $renaming, new InstanceException("The previous database {$names->aside} is gone.", ExitCode::RollbackFailed, 'previous_database_missing'));
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

    /**
     * Records that the way back is gone, when the databases were not renamed back yet (then the restored data is all
     * there is and `--abandon` continues with it), and throws.
     *
     * @throws InstanceException
     */
    private function noWayBack(OperationRun $run, bool $forwardPossible, InstanceException $e): never
    {
        if ($forwardPossible && ! $this->noted($run, self::NO_WAY_BACK)) {
            $run->note(self::NO_WAY_BACK, ['reason' => $e->errorCode]);
        }

        throw $e;
    }

    public function noted(OperationRun $run, string $type): bool
    {
        return array_any($run->record()->events(), static fn (array $event): bool => $event['type'] === $type);
    }

    private function volumeExists(string $volume): bool
    {
        return array_any($this->docker->volumes([]), static fn (DockerResource $resource): bool => $resource->name === $volume);
    }

    /** @param  array<string, mixed>  $data */
    private function failed(Throwable $cause, string $summary, array $data): CommandResult
    {
        $code = $cause instanceof InstanceException ? $cause->errorCode : 'restore_failed';
        $hint = $cause instanceof InstanceException && $cause->hint !== null ? $cause->hint : 'Read `fieldset logs app` and the output, fix the cause, then run `fieldset restore` again.';
        $details = $cause instanceof InstanceException ? $cause->data : [];

        return CommandResult::failed('restore', ExitCode::RolledBack, $code, $summary.' Cause: '.$cause->getMessage(), $hint, true, [...$details, ...$data], changed: true);
    }
}
