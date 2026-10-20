<?php

declare(strict_types=1);

namespace App\Domain\Instance\Upgrade;

use App\Domain\Instance\Docker\ComposeProject;
use App\Domain\Instance\Docker\DockerGateway;
use App\Domain\Instance\InstanceException;
use App\Domain\Instance\Operations\OperationRun;
use App\Domain\Instance\Output\CommandResult;
use App\Domain\Instance\Output\ExitCode;
use App\Domain\Instance\Restore\RestoreDatabases;
use App\Domain\Instance\Restore\RestoreHalt;
use App\Domain\Instance\Restore\RestoreNames;
use App\Domain\Instance\Restore\RestoreSource;
use App\Domain\Instance\Restore\RestoreStack;
use App\Domain\Instance\Restore\RestoreStorage;
use App\Domain\Instance\State\InstanceDirectory;
use App\Domain\Instance\State\InstanceState;
use App\Domain\Instance\State\RuntimeWriter;
use Throwable;

/**
 * The ways back of `fsa rollback` (plan U18, ADR 0086, review U18 A3, A8, A10, S1), journaled like a restore's (ADR 0085):
 *
 * - `abort()` (phase `abort`): a failure before the swap. The scratch database, the fresh volume, the stage and the
 *   scratch directory go; the gate goes down and queue, scheduler and backup start again (the upgraded app never
 *   stopped). Exit 9 when the cause changed nothing (a drain timeout, requests in flight: R69), exit 5 otherwise; exit 4
 *   when the workers do not start again (the upgraded app keeps serving, `fsa rollback` finishes going back).
 * - `returnTo()`: a failure from the swap until the gate went down, in steps a kill cannot repeat on a live instance:
 *   return_stop (writers stop) → return_database (the upgraded database renamed back, once: two journal notes) →
 *   return_files (the state before, and exactly the release files the swap replaced, from the swap's snapshot: never
 *   rendered again, whichever image runs this) → return_flush (Valkey) → return_verify (the app behind the gate passes
 *   the smoke test) → return_open (everything starts, orphans go, the gate goes down): exit 5. Once return_open
 *   completed, a resumed run only finishes. A failure is exit 8 with the way on, the safety backup and both digest sets.
 *
 * Safety backups left behind are sealed on every way back but an exit-8 halt (the recovery needs them plain).
 */
final readonly class RollbackRecovery
{
    public const string RETURNING = 'returning_database';

    public const string RETURNED = 'returned_database';

    public function __construct(
        private InstanceDirectory $directory,
        private DockerGateway $docker,
        private RestoreDatabases $databases,
        private RestoreStorage $storage,
        private RestoreStack $stack,
        private RestoreSource $source,
        private UpgradeQuiesce $quiesce,
        private UpgradeChild $child,
        private ReleaseFiles $files,
        private SafetySeal $seal,
    ) {}

    /** The failure an interrupted way back was going back from (its first phase's start data). */
    public static function cause(OperationRun $run, string $phase): InstanceException
    {
        $data = $run->record()->lastAttemptData($phase);

        return new InstanceException(is_string($data['message'] ?? null) ? $data['message'] : 'An earlier run of this rollback stopped half-way.', ExitCode::RolledBack,
            is_string($data['code'] ?? null) ? $data['code'] : 'rollback_interrupted');
    }

    /** The env files the previous image's render reads (current secret values, 0600): removed on every way out (review V4). */
    public static function envDirectory(string $id): string
    {
        return InstanceDirectory::OPERATIONS_DIR.'/rollback-env-'.$id;
    }

    /** The snapshot of the live release files a rollback's swap replaces (ReleaseFiles). */
    public static function filesBefore(string $id): string
    {
        return 'files-before-rollback-'.$id;
    }

    /** @param  array<string, mixed>  $plan */
    public function abort(OperationRun $run, InstanceState $state, ComposeProject $project, RestoreNames $names, Throwable $cause, bool $quiesced, array $plan): CommandResult
    {
        try {
            $data = $run->phase('abort', function () use ($state, $project, $names, $quiesced): array {
                $left = $this->dropScratch($project, $names);
                if ($quiesced) {
                    $this->quiesce->revert($state, $project, RollbackOperation::OPERATION);
                }

                return ['left_behind' => $left];
            }, $this->described($cause));
        } catch (Throwable $e) {
            if ($e instanceof InstanceException && $e->exitCode === ExitCode::HealthCheckFailed) { // the upgraded app serves (review A8)
                return $this->sealed(CommandResult::fromException('rollback', $e, true), $run);
            }

            return $this->halt($run, $plan, $project, $names, $cause, $e, 'abort');
        }
        $unchanged = $cause instanceof InstanceException && $cause->exitCode === ExitCode::AbortedUnchanged;
        $result = $this->sealed($this->failed($cause, 'The rollback failed before the swap; the instance runs '.$state->version().' as before.', ['left_behind' => RestoreNames::strings($data['left_behind'] ?? null)]), $run);
        if (! $unchanged) {
            return $result;
        }
        $run->markReverted(); // everything is back: the journal closes, as an upgrade's revert does (review V1)

        return $result->withExitCode(ExitCode::AbortedUnchanged);
    }

    /** @param  array<string, mixed>  $plan */
    public function returnTo(OperationRun $run, array $plan, ComposeProject $project, RestoreNames $names, Throwable $cause): CommandResult
    {
        $before = InstanceState::fromArray(is_array($plan['state_before'] ?? null) ? $plan['state_before'] : []);
        if (! $run->record()->hasCompleted('return_open')) {
            try {
                $this->goBack($run, $before, $project, $names, $this->described($cause));
            } catch (Throwable $e) {
                return $this->halt($run, $plan, $project, $names, $cause, $e, 'return');
            }
        }
        $left = $this->dropScratch($project, $names);
        $this->files->discard(self::filesBefore($names->id));

        return $this->sealed($this->failed($cause, 'The rolled-back instance did not pass its verification; '.$before->version().' runs again with its own database and storage.', ['left_behind' => $left, 'returned' => true]), $run);
    }

    /** @return list<string> what could not be removed */
    public function dropScratch(ComposeProject $project, RestoreNames $names, bool $databases = true): array
    {
        $left = [];
        UpgradeFiles::remove($this->directory->path(self::envDirectory($names->id)));
        if ($databases) {
            try {
                $this->databases->drop($project, $names->scratch);
            } catch (Throwable) {
                $left[] = "database {$names->scratch}";
            }
            if ($names->freshVolume !== $names->currentVolume) {
                $this->storage->remove($names->freshVolume);
            }
            UpgradeFiles::remove($this->child->stage($names->id));
        }
        try {
            $this->source->discard($names->id);
        } catch (Throwable) {
            $left[] = 'directory '.RestoreSource::scratchPath($names->id);
        }

        return $left;
    }

    /** @param  array{code: string, message: string}  $cause */
    private function goBack(OperationRun $run, InstanceState $before, ComposeProject $project, RestoreNames $names, array $cause): void
    {
        $run->phase('return_stop', function () use ($project): array {
            $this->docker->composeStop($project, RestoreHalt::WRITERS);

            return ['stopped' => RestoreHalt::WRITERS];
        }, $cause);
        $run->phase('return_database', function () use ($run, $project, $names): array {
            $this->renameBack($run, $project, $names);

            return ['live' => $names->live];
        });
        $run->phase('return_files', function () use ($before, $names): array {
            $this->directory->saveState($before);
            $restored = $this->files->restore(self::filesBefore($names->id)); // none: the swap never reached its files
            new RuntimeWriter($this->directory)->write($before);

            return ['restored' => $restored];
        });
        $run->phase('return_flush', fn (): array => ['flushed' => $this->stack->flushValkey($project)]);
        if (! $run->record()->hasStarted('return_open')) { // verified already; the gate may be down: only open again (review V2)
            $run->phase('return_verify', fn (): array => ['checked' => $this->stack->startAndVerify($before, $project, $this->stack->raise($before))], changes: false, always: true);
        }
        $run->phase('return_open', function () use ($before, $project): array {
            $services = array_values(array_diff(UpgradeCompose::services((string) $this->directory->read(InstanceDirectory::COMPOSE_FILE)), ['tunnel']));
            $this->docker->composeUp($project, $services, RestoreStack::WAIT_SECONDS, removeOrphans: true);
            $this->stack->lowerGate($before);

            return ['started' => $services];
        }, always: true);
    }

    /**
     * Renames the upgraded database back under the live name, once (journal notes make a resumed return safe): swapped
     * back when both names exist, renamed back when the live name is free (killed between the swap's two renames). A
     * completed swap whose put-aside database is gone, with no return that renamed it, cannot be undone: exit 8.
     */
    private function renameBack(OperationRun $run, ComposeProject $project, RestoreNames $names): void
    {
        $noted = static fn (string $type): bool => array_any($run->record()->events(), static fn (array $event): bool => $event['type'] === $type);
        if ($noted(self::RETURNED)) {
            return;
        }
        $databases = $this->databases->list($project, '');
        $live = in_array($names->live, $databases, true);
        if (in_array($names->aside, $databases, true)) {
            $run->note(self::RETURNING, ['live' => $names->live]);
            if ($live) {
                $this->databases->swap($project, $names->aside, $names->live, $names->scratch);
            } else {
                $this->databases->rename($project, $names->aside, $names->live);
            }
        } elseif (! $live || ($run->record()->hasCompleted('swap') && ! $noted(self::RETURNING))) {
            throw new InstanceException("The database before the rollback ({$names->aside}) is gone.", ExitCode::RollbackFailed, 'previous_database_missing');
        }
        $run->note(self::RETURNED, ['live' => $names->live]);
    }

    /**
     * Exit 8 (R55): the instance stopped behind its gate, where each database and volume is, the safety backup taken
     * before the rollback (kept plain on purpose) and both digest sets.
     *
     * @param  array<string, mixed>  $plan
     */
    private function halt(OperationRun $run, array $plan, ComposeProject $project, RestoreNames $names, Throwable $cause, Throwable $failure, string $kind): CommandResult
    {
        try {
            $this->docker->composeStop($project, RestoreHalt::WRITERS);
        } catch (Throwable) {
            // already stopped or Docker unreachable
        }
        $where = $kind === 'abort'
            ? "Nothing was swapped: {$names->live} and {$names->currentVolume} are unchanged."
            : "The database before the rollback is {$names->aside} (or {$names->live}), the restored one {$names->live} (or {$names->scratch}); the storage volume before is {$names->currentVolume}, the restored one {$names->freshVolume}.";
        $safety = UpgradeJournal::safetyBackups($run->record())[0] ?? null;
        $path = $safety === null ? null : $this->directory->root.'/'.InstanceDirectory::BACKUPS_DIR.'/'.$safety;
        $before = is_array($plan['state_before'] ?? null) ? $plan['state_before'] : [];

        return CommandResult::failed('rollback', ExitCode::RollbackFailed, 'rollback_return_failed',
            'The rollback failed ('.$cause->getMessage().') and going back failed too ('.$failure->getMessage().'); the instance is stopped behind its gate.',
            $where.' Read `fsa logs app`, then run `fsa rollback` again to continue going back.'.($path === null ? '' : " The data before the rollback is in the safety backup {$path}."), false,
            ['names' => $names->toArray(), 'next' => 'fsa rollback', 'safety_backup' => $path,
                'digests' => ['previous' => UpgradeJournal::strings($plan['digests'] ?? null), 'current' => UpgradeJournal::strings($before['digests'] ?? null)]],
            changed: true);
    }

    /** Seals the safety backups this rollback took and the upgrade's it restored from (review S1). */
    private function sealed(CommandResult $result, OperationRun $run): CommandResult
    {
        $record = $run->record();
        $plan = $record->phaseData('plan');
        $backups = [...UpgradeJournal::safetyBackups($record), ...$this->seal->since(RollbackOperation::OPERATION, $record->startedAt()), is_string($plan['backup'] ?? null) ? $plan['backup'] : null];
        foreach ($this->seal->seal($backups) as [$code, $message, $hint]) {
            $result = $result->withWarning($code, $message, $hint);
        }

        return $result;
    }

    /** @return array{code: string, message: string} */
    private function described(Throwable $cause): array
    {
        return ['code' => $cause instanceof InstanceException ? $cause->errorCode : 'rollback_failed', 'message' => $cause->getMessage()];
    }

    /** @param  array<string, mixed>  $data */
    private function failed(Throwable $cause, string $summary, array $data): CommandResult
    {
        $code = $cause instanceof InstanceException ? $cause->errorCode : 'rollback_failed';
        $hint = $cause instanceof InstanceException && $cause->hint !== null ? $cause->hint : 'Read `fsa logs app` and the output, fix the cause, then run `fsa rollback` again.';

        return CommandResult::failed('rollback', ExitCode::RolledBack, $code, $summary.' Cause: '.$cause->getMessage(), $hint, true, [...($cause instanceof InstanceException ? $cause->data : []), ...$data], changed: true);
    }
}
