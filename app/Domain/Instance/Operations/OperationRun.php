<?php

declare(strict_types=1);

namespace App\Domain\Instance\Operations;

use App\Domain\Instance\Output\ProgressReporter;
use Closure;
use Throwable;

/**
 * The phase runner a supervised operation receives. `phase()` journals the start before the step runs and the
 * step's resume data after it; on a resumed run, completed phases are skipped and return their recorded data.
 * It tracks whether a changing phase has started, which decides between exit 9 and a resumable failure (R69), and
 * writes the lock heartbeat before and after every phase, so long steps (`up --wait`, migrations) are bracketed.
 */
final class OperationRun
{
    private bool $changed;

    /** @param  (Closure(): void)|null  $heartbeat  best effort: a failing heartbeat never fails the operation */
    public function __construct(
        private readonly OperationJournal $journal,
        private JournalRecord $record,
        private readonly ?ProgressReporter $progress = null,
        private readonly ?Closure $heartbeat = null,
    ) {
        $this->changed = $record->hasChanges();
    }

    /**
     * @param  Closure(): array<string, mixed>  $step  returns the data a resumed run needs from this phase
     * @param  array<string, mixed>  $data  resume data known before the step runs
     * @param  bool  $changes  false for read-only phases (doctor, verify, pull)
     * @param  bool  $always  run even when an earlier attempt completed it (operations that restart from a snapshot
     *                        on resume, e.g. `config set`); the journal counts the attempts
     * @return array<string, mixed>
     */
    public function phase(string $name, Closure $step, array $data = [], bool $changes = true, bool $always = false): array
    {
        if (! $always && $this->record->hasCompleted($name)) {
            return $this->record->phaseData($name);
        }
        $this->record = $this->journal->startPhase($name, $data, $changes);
        $this->changed = $this->changed || $changes;
        $this->progress?->phase($name, "Starting {$name}");
        $this->heartbeat();

        try {
            $result = $step();
        } catch (Throwable $e) {
            $this->record = $this->journal->failPhase($name, $e->getMessage());

            throw $e;
        }
        $this->record = $this->journal->completePhase($name, $result);
        $this->heartbeat();

        return $result;
    }

    /** Records liveness of the lock holder (`fsa status`); cheap, and never fails the operation. */
    public function heartbeat(): void
    {
        if (! $this->heartbeat instanceof Closure) {
            return;
        }

        try {
            ($this->heartbeat)();
        } catch (Throwable) {
            // best effort: a lost heartbeat only makes `fsa status` show an older time
        }
    }

    /**
     * The operation undid every change it made (`config set` reverted to the previous settings): from now on it
     * counts as one that changed nothing, so the supervisor archives its journal instead of leaving it open.
     *
     * @param  array<string, mixed>  $data
     */
    public function markReverted(array $data = []): void
    {
        $this->note('reverted', $data);
        $this->changed = false;
    }

    /** Whether this run continues an earlier, unfinished journal of the same operation. */
    public function isResumed(): bool
    {
        return array_any($this->record->events(), static fn (array $event): bool => $event['type'] === 'resumed');
    }

    /** @param  array<string, mixed>  $data */
    public function note(string $type, array $data = []): void
    {
        $this->record = $this->journal->note($type, $data);
    }

    /** @return array<string, mixed> */
    public function resumeData(): array
    {
        return $this->record->resumeData();
    }

    public function record(): JournalRecord
    {
        return $this->record;
    }

    public function hasChanged(): bool
    {
        return $this->changed;
    }
}
