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
use Throwable;

/**
 * The ways back of `fsa rollback` (plan U18, ADR 0086), journaled like a restore's (ADR 0085):
 *
 * - `abort()` (phase `abort`): a failure before the swap. The scratch database, the fresh volume, the stage and the
 *   scratch directory go; the gate goes down and queue, scheduler and backup start again (the upgraded app never
 *   stopped): exit 5, the upgraded release runs as before.
 * - `returnTo()` (phase `return`): a failure from the swap until the gate went down. Every writer stops, the upgraded
 *   database is renamed back (once: two journal notes), the state and compose file before the rollback come back,
 *   Valkey is flushed, the app passes the smoke test behind the gate, then everything starts and the gate goes down:
 *   exit 5. When that fails, exit 8 with the way on (`fsa rollback` continues returning).
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
    ) {}

    /** The failure an interrupted way back was going back from (its phase's start data). */
    public static function cause(OperationRun $run, string $phase): InstanceException
    {
        $data = $run->record()->lastAttemptData($phase);

        return new InstanceException(is_string($data['message'] ?? null) ? $data['message'] : 'An earlier run of this rollback stopped half-way.', ExitCode::RolledBack,
            is_string($data['code'] ?? null) ? $data['code'] : 'rollback_interrupted');
    }

    public function abort(OperationRun $run, InstanceState $state, ComposeProject $project, RestoreNames $names, Throwable $cause, bool $quiesced): CommandResult
    {
        try {
            $data = $run->phase('abort', function () use ($state, $project, $names, $quiesced): array {
                $left = $this->dropScratch($project, $names);
                if ($quiesced) {
                    $this->quiesce->revert($state, $project);
                }

                return ['left_behind' => $left];
            }, self::described($cause));
        } catch (Throwable $e) {
            return $this->halt($project, $names, $cause, $e, 'abort');
        }

        return self::failed($cause, 'The rollback failed before the swap; the instance runs '.$state->version().' as before.', ['left_behind' => RestoreNames::strings($data['left_behind'] ?? null)]);
    }

    /** @param  array<string, mixed>  $plan */
    public function returnTo(OperationRun $run, array $plan, ComposeProject $project, RestoreNames $names, Throwable $cause): CommandResult
    {
        $before = InstanceState::fromArray(is_array($plan['state_before'] ?? null) ? $plan['state_before'] : []);
        try {
            $run->phase('return', function () use ($run, $before, $plan, $project, $names): array {
                $this->docker->composeStop($project, RestoreHalt::WRITERS);
                $this->renameBack($run, $project, $names);
                $this->directory->saveState($before);
                $this->directory->write(InstanceDirectory::COMPOSE_FILE, (string) ($plan['compose_before'] ?? ''), 0600);
                $this->stack->apply($before); // env files and runtime of the upgraded release again
                $this->stack->flushValkey($project);
                $this->stack->startAndVerify($before, $project, $this->stack->raise($before));
                $services = array_values(array_diff(UpgradeCompose::services((string) $this->directory->read(InstanceDirectory::COMPOSE_FILE)), ['tunnel']));
                $this->docker->composeUp($project, $services, RestoreStack::WAIT_SECONDS);
                $this->stack->lowerGate($before);

                return ['returned' => true];
            }, self::described($cause));
        } catch (Throwable $e) {
            return $this->halt($project, $names, $cause, $e, 'return');
        }
        $left = $this->dropScratch($project, $names);

        return self::failed($cause, 'The rolled-back instance did not pass its verification; '.$before->version().' runs again with its own database and storage.', ['left_behind' => $left, 'returned' => true]);
    }

    /** @return list<string> what could not be removed */
    public function dropScratch(ComposeProject $project, RestoreNames $names, bool $databases = true): array
    {
        $left = [];
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
            $left[] = 'directory backups/'.RestoreSource::SCRATCH_PREFIX.$names->id;
        }

        return $left;
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

    private function halt(ComposeProject $project, RestoreNames $names, Throwable $cause, Throwable $failure, string $kind): CommandResult
    {
        try {
            $this->docker->composeStop($project, RestoreHalt::WRITERS);
        } catch (Throwable) {
            // already stopped or Docker unreachable
        }
        $where = $kind === 'abort'
            ? "Nothing was swapped: {$names->live} and {$names->currentVolume} are unchanged."
            : "The database before the rollback is {$names->aside} (or {$names->live}), the restored one {$names->live} (or {$names->scratch}); the storage volume before is {$names->currentVolume}, the restored one {$names->freshVolume}.";

        return CommandResult::failed('rollback', ExitCode::RollbackFailed, 'rollback_return_failed',
            'The rollback failed ('.$cause->getMessage().') and going back failed too ('.$failure->getMessage().'); the instance is stopped behind its gate.',
            $where.' Read `fsa logs app`, then run `fsa rollback` again to continue going back.', false, ['names' => $names->toArray(), 'next' => 'fsa rollback'], changed: true);
    }

    /** @return array{code: string, message: string} */
    private static function described(Throwable $cause): array
    {
        return ['code' => $cause instanceof InstanceException ? $cause->errorCode : 'rollback_failed', 'message' => $cause->getMessage()];
    }

    /** @param  array<string, mixed>  $data */
    private static function failed(Throwable $cause, string $summary, array $data): CommandResult
    {
        $code = $cause instanceof InstanceException ? $cause->errorCode : 'rollback_failed';
        $hint = $cause instanceof InstanceException && $cause->hint !== null ? $cause->hint : 'Read `fsa logs app` and the output, fix the cause, then run `fsa rollback` again.';

        return CommandResult::failed('rollback', ExitCode::RolledBack, $code, $summary.' Cause: '.$cause->getMessage(), $hint, true, [...($cause instanceof InstanceException ? $cause->data : []), ...$data], changed: true);
    }
}
