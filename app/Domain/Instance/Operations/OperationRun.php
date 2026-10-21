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
 *
 * Progress (KTD3, ADR 0078): "Starting <phase>" names the phase's expected maximum duration when the caller knows
 * it (`max_seconds` in the NDJSON event), and every phase ends with a "Completed" or "failed" event with the
 * seconds it took, so an agent watching stderr can tell a slow step from a hung one.
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
     * @param  int|null  $maxSeconds  how long the step may take at most (timeouts and retries), for the progress event
     * @return array<string, mixed>
     */
    public function phase(string $name, Closure $step, array $data = [], bool $changes = true, bool $always = false, ?int $maxSeconds = null): array
    {
        if (! $always && $this->record->hasCompleted($name)) {
            return $this->record->phaseData($name);
        }
        $this->record = $this->journal->startPhase($name, $data, $changes);
        $this->changed = $this->changed || $changes;
        $this->progress?->event($name, ProgressReporter::INFO, "Starting {$name}".($maxSeconds === null ? '' : ' (at most '.self::duration($maxSeconds).')'), $maxSeconds === null ? [] : ['max_seconds' => $maxSeconds]);
        $this->heartbeat();
        $started = hrtime(true);

        try {
            $result = $step();
        } catch (Throwable $e) {
            $this->record = $this->journal->failPhase($name, $e->getMessage());
            $this->progress?->event($name, ProgressReporter::ERROR, "{$name} failed after ".self::duration($this->since($started)).": {$e->getMessage()}", ['elapsed_seconds' => $this->since($started)]);

            throw $e;
        }
        $this->record = $this->journal->completePhase($name, $result);
        $this->progress?->event($name, ProgressReporter::INFO, "Completed {$name} in ".self::duration($this->since($started)), ['elapsed_seconds' => $this->since($started)]);
        $this->heartbeat();

        return $result;
    }

    /** "45 s", "7 min" (whole minutes from two minutes on). */
    public static function duration(int $seconds): string
    {
        return $seconds >= 120 ? intdiv($seconds, 60).' min' : "{$seconds} s";
    }

    private function since(int|float $started): int
    {
        return (int) round((hrtime(true) - $started) / 1e9);
    }

    /** Records liveness of the lock holder (`fieldset status`); cheap, and never fails the operation. */
    public function heartbeat(): void
    {
        if (! $this->heartbeat instanceof Closure) {
            return;
        }

        try {
            ($this->heartbeat)();
        } catch (OperationInterrupted $interrupted) {
            throw $interrupted; // a signal that landed here interrupts the operation, it is no heartbeat failure
        } catch (Throwable) {
            // best effort: a lost heartbeat only makes `fieldset status` show an older time
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
