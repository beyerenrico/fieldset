<?php

declare(strict_types=1);

namespace App\Domain\Instance\Reset;

use App\Domain\Instance\Docker\ComposeProject;
use App\Domain\Instance\Docker\DockerGateway;
use App\Domain\Instance\InstanceException;
use App\Domain\Instance\Operations\JournalRecord;
use App\Domain\Instance\Operations\OperationRun;
use App\Domain\Instance\Output\CommandResult;
use App\Domain\Instance\Output\ExitCode;
use App\Domain\Instance\Restore\RestoreHalt;
use App\Domain\Instance\Restore\RestoreNames;
use App\Domain\Instance\Restore\RestoreRecovery;
use App\Domain\Instance\Restore\RestoreStack;
use App\Domain\Instance\State\InstanceState;
use Throwable;

/**
 * The way back of a reset before the drop (plan U23, ADR 0088; U23 review A1, A2), as restore goes back before its swap
 * (ADR 0085, U17 M2/L4/L7):
 *
 * - journaled as the phase `abort` before anything is touched, with the cause as its start data: a run killed while going
 *   back, or one that ended in exit 8, is finished as an abort by the next `fsa reset` (or `fsa reset --abandon`), never
 *   resumed forward over the writes the reopened instance took;
 * - `RestoreStack::reopenPrevious()`: the unchanged app starts, the gate goes down once it is healthy, the other services
 *   start (one that does not is a warning);
 * - when the app does not become healthy, every writer stops (RestoreHalt::WRITERS), the gate stays up and the journal
 *   open: exit 8, and `fsa reset --abandon` tries again.
 *
 * `--abandon` (A2) is the same way back on request: before the drop only, closing the journal with `ok`.
 */
final readonly class ResetWayBack
{
    public const string PHASE = 'abort';

    public function __construct(private DockerGateway $docker, private RestoreStack $stack, private ResetRebuild $rebuild) {}

    /** A failure before the drop: the previous instance runs again (exit 5, nothing deleted), or exit 8. */
    public function abort(OperationRun $run, InstanceState $state, ComposeProject $project, Throwable $cause): CommandResult
    {
        try {
            $degraded = $this->goBack($run, $state, $project, $cause);
        } catch (Throwable $e) {
            return $this->halt($project, $cause, $e, $run->record());
        }
        $code = $cause instanceof InstanceException ? $cause->errorCode : 'reset_failed';
        $hint = $cause instanceof InstanceException && $cause->hint !== null ? $cause->hint : 'Fix the cause, then run `fsa reset` again.';
        $result = CommandResult::failed(ResetOperation::OPERATION, ExitCode::RolledBack, $code, 'The reset failed before anything was deleted; the instance runs as before. Cause: '.$cause->getMessage(),
            $hint, true, [...($cause instanceof InstanceException ? $cause->data : []), 'safety_backup' => ResetResult::safetyBackup($run->record())], changed: true);

        return $this->rebuild->seal(RestoreRecovery::degraded($result, $degraded), $run->record());
    }

    /** `fsa reset --abandon` before the drop: the previous instance runs again and the journal closes. */
    public function abandon(OperationRun $run, InstanceState $state, ComposeProject $project): CommandResult
    {
        $cause = new InstanceException('The reset was abandoned.', ExitCode::RolledBack, 'reset_abandoned');
        try {
            $degraded = $this->goBack($run, $state, $project, $cause);
        } catch (Throwable $e) {
            return $this->halt($project, $cause, $e, $run->record());
        }
        $result = CommandResult::ok(ResetOperation::OPERATION, ['abandoned' => true, 'database_recreated' => false, 'safety_backup' => ResetResult::safetyBackup($run->record())], changed: true);

        return $this->rebuild->seal(RestoreRecovery::degraded($result, $degraded), $run->record());
    }

    /** The failure an interrupted way back was going back from (its phase's start data). */
    public static function cause(OperationRun $run): InstanceException
    {
        $data = $run->record()->lastAttemptData(self::PHASE);

        return new InstanceException(
            is_string($data['message'] ?? null) ? $data['message'] : 'An earlier run of this reset stopped while going back.',
            ExitCode::RolledBack, is_string($data['code'] ?? null) ? $data['code'] : 'reset_interrupted',
        );
    }

    /**
     * Journaled before anything is touched (a completed way back is not run again).
     *
     * @return list<string> the services that did not start
     */
    private function goBack(OperationRun $run, InstanceState $state, ComposeProject $project, Throwable $cause): array
    {
        $data = $run->phase(self::PHASE, fn (): array => ['degraded' => $this->stack->reopenPrevious($state, $project)],
            $cause instanceof InstanceException ? ['code' => $cause->errorCode, 'message' => $cause->getMessage()] : ['code' => 'reset_failed', 'message' => $cause->getMessage()]);

        return RestoreNames::strings($data['degraded'] ?? null);
    }

    /** Exit 8: every writer stops, the gate stays up, the journal stays open; nothing was deleted. */
    private function halt(ComposeProject $project, Throwable $cause, Throwable $failure, JournalRecord $record): CommandResult
    {
        try {
            $this->docker->composeStop($project, RestoreHalt::WRITERS);
        } catch (Throwable) {
            // already stopped or Docker unreachable: the result says what to run either way
        }

        return CommandResult::failed(ResetOperation::OPERATION, ExitCode::RollbackFailed, 'reset_rollback_failed',
            'The reset failed before anything was deleted ('.$cause->getMessage().'), and starting the instance again failed too ('.$failure->getMessage().'); every writer is stopped behind the gate.',
            'Nothing was deleted. Read `fsa logs app`, then run `fsa reset --abandon` (or the same `'.ResetRequest::commandLine($record->context()).'`) to start the instance again.',
            false, ['safety_backup' => ResetResult::safetyBackup($record)], changed: true);
    }
}
