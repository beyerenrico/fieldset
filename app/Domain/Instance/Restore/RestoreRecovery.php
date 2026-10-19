<?php

declare(strict_types=1);

namespace App\Domain\Instance\Restore;

use App\Domain\Instance\Backup\KeyFingerprint;
use App\Domain\Instance\Compose\Services\AppRoles;
use App\Domain\Instance\Compose\Services\BackupService;
use App\Domain\Instance\Docker\ComposeProject;
use App\Domain\Instance\Docker\DockerGateway;
use App\Domain\Instance\Docker\DockerResource;
use App\Domain\Instance\InstanceException;
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
 *   directory go, the gate goes down and the previous stack starts again: exit 5, the old instance runs.
 * - `returnToPrevious()`: a failure from the swap on (or an interrupted swap found by a resumed run). The put-aside
 *   database becomes the live one again, the state names the previous storage volume, Valkey is flushed, the app must
 *   pass the smoke test behind the gate, then everything starts: exit 5. When that fails too, every frontend stops,
 *   the gate stays up and the result names what is where: exit 8.
 */
final readonly class RestoreRecovery
{
    public function __construct(
        private InstanceDirectory $directory,
        private DockerGateway $docker,
        private RestoreDatabases $databases,
        private RestoreStorage $storage,
        private RestoreStack $stack,
        private RestoreSource $source,
    ) {}

    public function abort(InstanceState $state, ComposeProject $project, RestoreNames $names, Throwable $cause, bool $quiesced): CommandResult
    {
        $left = $this->dropScratch($project, $names);
        if ($quiesced) {
            try {
                $this->stack->reopen($state, $project);
            } catch (Throwable $e) {
                return $this->stopped($project, $names, $cause, $e, null);
            }
        }

        return $this->failed($cause, ExitCode::RolledBack, 'The restore failed before the swap; the instance runs as before.', ['left_behind' => $left]);
    }

    /**
     * @param  InstanceState  $previous  the state before the swap (its storage volume)
     * @param  list<string>  $addedKeys  fingerprints of APP keys the swap added
     */
    public function returnToPrevious(InstanceState $previous, ComposeProject $project, RestoreNames $names, Throwable $cause, array $addedKeys, ?string $safetyBackup): CommandResult
    {
        try {
            $databases = $this->databases->list($project, $names->live);
            if (in_array($names->aside, $databases, true)) {
                if (! in_array($names->live, $databases, true)) {
                    throw new InstanceException("Only {$names->aside} exists; the live database name is free.", ExitCode::RollbackFailed, 'swap_half_done');
                }
                $this->databases->swap($project, $names->aside, $names->live, $names->scratch);
            }
            $secrets = $this->stack->secrets();
            $kept = array_values(array_filter($secrets->previousKeys, static fn (string $key): bool => ! in_array(KeyFingerprint::of($key), $addedKeys, true)));
            $this->stack->apply($previous, $secrets->withPreviousKeys($kept));
            $this->stack->flushValkey($project);
            $token = new RuntimeWriter($this->directory)->raiseGate($previous, 'restore');
            $this->stack->startAndVerify($previous, $project, $token);
            $this->stack->reopen($previous, $project);
        } catch (Throwable $e) {
            return $this->stopped($project, $names, $cause, $e, $safetyBackup);
        }
        $left = $this->dropScratch($project, $names);

        return $this->failed($cause, ExitCode::RolledBack, 'The restored instance did not pass its verification; the previous database and storage volume are back.', ['left_behind' => $left, 'returned_to_previous' => true]);
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
            if ($names->freshVolume !== $names->currentVolume && array_any($this->docker->volumes([]), static fn (DockerResource $volume): bool => $volume->name === $names->freshVolume) && ! $this->storage->remove($names->freshVolume)) {
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

    private function stopped(ComposeProject $project, RestoreNames $names, Throwable $cause, Throwable $failure, ?string $safetyBackup): CommandResult
    {
        try {
            $this->docker->composeStop($project, [AppRoles::APP, AppRoles::QUEUE, AppRoles::SCHEDULER, BackupService::NAME]);
        } catch (Throwable) {
            // already stopped or Docker unreachable: the result says what is where either way
        }

        return CommandResult::failed('restore', ExitCode::RollbackFailed, 'restore_rollback_failed',
            'The restore failed ('.$cause->getMessage().') and returning to the previous instance failed too ('.$failure->getMessage().'); the instance is stopped behind its gate.',
            "The previous database is {$names->aside} (or {$names->live}), the restored one {$names->live} (or {$names->scratch}); the previous storage volume is {$names->currentVolume}, the restored one {$names->freshVolume}. Run `fsa restore --abandon` to try again, or restore the safety backup".($safetyBackup === null ? '' : " {$safetyBackup}").' with `fsa restore`.',
            false, ['names' => $names->toArray(), 'safety_backup' => $safetyBackup], changed: true);
    }

    /** @param  array<string, mixed>  $data */
    private function failed(Throwable $cause, ExitCode $exit, string $summary, array $data): CommandResult
    {
        $code = $cause instanceof InstanceException ? $cause->errorCode : 'restore_failed';
        $hint = $cause instanceof InstanceException && $cause->hint !== null ? $cause->hint : 'Read `fsa logs app` and the output, fix the cause, then run `fsa restore` again.';
        $details = $cause instanceof InstanceException ? $cause->data : [];

        return CommandResult::failed('restore', $exit, $code, $summary.' Cause: '.$cause->getMessage(), $hint, true, [...$details, ...$data], changed: true);
    }
}
