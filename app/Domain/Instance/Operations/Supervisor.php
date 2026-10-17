<?php

declare(strict_types=1);

namespace App\Domain\Instance\Operations;

use App\Domain\Instance\Docker\ContainerSpec;
use App\Domain\Instance\Docker\DockerCommandFailed;
use App\Domain\Instance\Docker\DockerGateway;
use App\Domain\Instance\Docker\Labels;
use App\Domain\Instance\InstanceException;
use App\Domain\Instance\Output\CommandResult;
use App\Domain\Instance\Output\ExitCode;
use App\Domain\Instance\Output\Message;
use App\Domain\Instance\Output\ProgressReporter;
use App\Domain\Instance\State\InstanceState;
use Closure;

/**
 * Runs a state-changing operation under the instance lock with its journal (R54, R69, KTD4, KTD5).
 *
 * - Lock first; a stale lock taken over is journaled (`lock_takeover`).
 * - An unfinished journal blocks a different operation (exit 9); the same operation continues with `resume`.
 * - A failure before any changing phase exits 9 (or its own pre-change code, e.g. 3) and archives the journal;
 *   after a change the journal stays open so a re-run can resume, unless the outcome closes it (rolled back).
 *
 * Long operations (upgrade, restore, adopt, reset) run this inside a detached supervisor container of the
 * installed image (`launchDetached`), so they survive the SSH session.
 */
final readonly class Supervisor
{
    public const string ROLE = 'supervisor';

    public function __construct(
        private InstanceLock $lock,
        private OperationJournal $journal,
        private DockerGateway $docker,
    ) {}

    /**
     * @param  Closure(OperationRun): CommandResult  $steps
     * @param  array<string, mixed>  $context  inputs recorded with a new journal
     *
     * @throws LockHeld|OperationUnfinished
     */
    public function run(string $operation, LockHolder $holder, Closure $steps, bool $resume = false, array $context = [], ?ProgressReporter $progress = null): CommandResult
    {
        $handle = $this->lock->acquire($holder, $operation);

        try {
            $run = new OperationRun($this->journal, $this->openJournal($operation, $holder, $handle, $resume, $context), $progress);

            try {
                $result = $steps($run);
            } catch (InstanceException $e) {
                $result = $this->failure($operation, $e, $run);
            }

            return $this->conclude($result, $run);
        } finally {
            $this->lock->release($handle);
        }
    }

    /** Starts the detached supervisor container (one per instance) and returns its container ID. */
    public function launchDetached(InstanceState $state, ContainerSpec $spec): string
    {
        $labels = Labels::forInstance($state->uuid(), self::ROLE);
        if ($this->docker->containers($labels, all: false) !== []) {
            throw new InstanceException('A supervisor for this instance is already running.', ExitCode::AbortedUnchanged, 'supervisor_running', 'Follow it with `fsa status`.', true);
        }
        $outcome = $this->docker->runContainer($spec->detachedAs($state->project().'-'.self::ROLE, $labels));
        if (! $outcome->successful()) {
            throw new DockerCommandFailed('run', $outcome);
        }

        return trim($outcome->stdout);
    }

    /** @param  array<string, mixed>  $context */
    private function openJournal(string $operation, LockHolder $holder, LockHandle $handle, bool $resume, array $context): JournalRecord
    {
        $current = $this->journal->current();
        if ($current instanceof JournalRecord && $handle->replaced instanceof LockRecord) {
            $current = $this->journal->note('lock_takeover', ['previous' => $handle->replaced->toArray()]);
        }
        if ($current instanceof JournalRecord) {
            if (! $resume || $current->operation() !== $operation) {
                throw new OperationUnfinished($current);
            }

            return $this->journal->note('resumed', ['host_uid' => $holder->hostUid]);
        }
        $record = $this->journal->begin($operation, $holder->hostUid, $context);

        return $handle->replaced instanceof LockRecord
            ? $this->journal->note('lock_takeover', ['previous' => $handle->replaced->toArray()])
            : $record;
    }

    /** R69: an unclassified failure before any change is "aborted, nothing changed" (exit 9). */
    private function failure(string $operation, InstanceException $e, OperationRun $run): CommandResult
    {
        $result = CommandResult::fromException($operation, $e, $run->hasChanged());
        if (! $run->hasChanged() && ! $e->exitCode->isBeforeChange()) {
            return $result->withExitCode(ExitCode::AbortedUnchanged);
        }

        return $result;
    }

    private function conclude(CommandResult $result, OperationRun $run): CommandResult
    {
        if (! $run->hasChanged() || $result->status()->closesOperation()) {
            $this->journal->finish($result->status(), ['exit_code' => $result->exitCode->value]);
        } else {
            $run->note('failed', ['exit_code' => $result->exitCode->value, 'errors' => array_map(static fn (Message $m): string => $m->code, $result->errors)]);
        }

        return $result;
    }
}
