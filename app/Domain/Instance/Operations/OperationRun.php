<?php

declare(strict_types=1);

namespace App\Domain\Instance\Operations;

use App\Domain\Instance\Output\ProgressReporter;
use Closure;
use Throwable;

/**
 * The phase runner a supervised operation receives. `phase()` journals the start before the step runs and the
 * step's resume data after it; on a resumed run, completed phases are skipped and return their recorded data.
 * It tracks whether a changing phase has started, which decides between exit 9 and a resumable failure (R69).
 */
final class OperationRun
{
    private bool $changed;

    public function __construct(
        private readonly OperationJournal $journal,
        private JournalRecord $record,
        private readonly ?ProgressReporter $progress = null,
    ) {
        $this->changed = $record->hasChanges();
    }

    /**
     * @param  Closure(): array<string, mixed>  $step  returns the data a resumed run needs from this phase
     * @param  array<string, mixed>  $data  resume data known before the step runs
     * @param  bool  $changes  false for read-only phases (doctor, verify, pull)
     * @return array<string, mixed>
     */
    public function phase(string $name, Closure $step, array $data = [], bool $changes = true): array
    {
        if ($this->record->hasCompleted($name)) {
            return $this->record->phaseData($name);
        }
        $this->record = $this->journal->startPhase($name, $data, $changes);
        $this->changed = $this->changed || $changes;
        $this->progress?->phase($name, "Starting {$name}");

        try {
            $result = $step();
        } catch (Throwable $e) {
            $this->record = $this->journal->failPhase($name, $e->getMessage());

            throw $e;
        }
        $this->record = $this->journal->completePhase($name, $result);

        return $result;
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
