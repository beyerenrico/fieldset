<?php

declare(strict_types=1);

namespace App\Domain\Instance\Operations;

use App\Domain\Instance\Output\ResultStatus;
use App\Domain\Instance\State\InstanceDirectory;
use App\Domain\Instance\State\InvalidState;
use Carbon\CarbonImmutable;
use Closure;

/**
 * The operation journal (R54, plan "Upgrade journal"). Every phase is written before its step starts, as a whole
 * document via temp-file-plus-rename with fsync, so a killed process leaves the last started phase and its resume
 * data behind. A finished operation moves to the history, which keeps the newest HISTORY_LIMIT journals; an
 * unfinished one blocks new operations until it is resumed or rolled back. The journal stays private to the ops
 * side (KTD24).
 */
final readonly class OperationJournal
{
    public const string CURRENT = InstanceDirectory::OPERATIONS_DIR.'/current.json';

    public const string HISTORY_DIR = InstanceDirectory::OPERATIONS_DIR.'/history';

    /** Archived journals kept; older ones are deleted when an operation finishes. */
    public const int HISTORY_LIMIT = 20;

    public function __construct(private InstanceDirectory $directory) {}

    public function current(): ?JournalRecord
    {
        $json = $this->directory->read(self::CURRENT);

        return $json === null ? null : JournalRecord::fromArray(InstanceDirectory::decode($json, 'operation journal'));
    }

    /**
     * @param  array<string, mixed>  $context  flags and inputs the operation was started with
     *
     * @throws OperationUnfinished
     */
    public function begin(string $operation, ?int $hostUid, array $context = []): JournalRecord
    {
        $current = $this->current();
        if ($current instanceof JournalRecord) {
            throw new OperationUnfinished($current);
        }

        return $this->save(JournalRecord::start(bin2hex(random_bytes(8)), $operation, $hostUid, $context, $this->now()));
    }

    /**
     * @param  array<string, mixed>  $data  what a resumed run needs to continue from this phase
     * @param  bool  $changes  false for read-only phases
     */
    public function startPhase(string $phase, array $data = [], bool $changes = true): JournalRecord
    {
        return $this->save($this->open()->withPhaseStarted($phase, $data, $this->now(), $changes));
    }

    /** @param  array<string, mixed>  $data */
    public function completePhase(string $phase, array $data = []): JournalRecord
    {
        return $this->save($this->open()->withPhaseCompleted($phase, $data, $this->now()));
    }

    public function failPhase(string $phase, string $error): JournalRecord
    {
        return $this->save($this->open()->withPhaseFailed($phase, $error, $this->now()));
    }

    /** @param  array<string, mixed>  $data */
    public function note(string $type, array $data = []): JournalRecord
    {
        return $this->save($this->open()->withEvent($type, $data, $this->now()));
    }

    /**
     * Records the outcome and moves the journal to the history (oldest archives beyond HISTORY_LIMIT are deleted).
     *
     * @param  array<string, mixed>  $data
     * @param  (Closure(JournalRecord): JournalRecord)|null  $archive  rewrites the record before it is archived
     */
    public function finish(ResultStatus $outcome, array $data = [], ?Closure $archive = null): JournalRecord
    {
        $record = $this->open()->withOutcome($outcome, $data, $this->now());
        $record = $this->save($archive instanceof Closure ? $archive($record) : $record);
        $name = CarbonImmutable::parse($record->startedAt())->format('Ymd\THis\Z').'-'.$record->operation().'-'.$record->id().'.json';
        $this->directory->ensureDirectory(self::HISTORY_DIR);
        if (! $this->directory->files()->rename(self::CURRENT, self::HISTORY_DIR.'/'.$name)) {
            throw new InvalidState('The finished operation journal could not be archived.');
        }
        $this->prune();

        return $record;
    }

    /** @return list<JournalRecord> newest first */
    public function history(int $limit = 20): array
    {
        $names = array_reverse(array_filter($this->directory->files()->list(self::HISTORY_DIR), static fn (string $name): bool => str_ends_with($name, '.json')));
        $records = [];
        foreach (array_slice($names, 0, $limit) as $name) {
            $records[] = JournalRecord::fromArray(InstanceDirectory::decode((string) $this->directory->read(self::HISTORY_DIR.'/'.$name), 'operation journal'));
        }

        return $records;
    }

    /** Archive names start with the UTC start time, so name order is age order. */
    private function prune(): void
    {
        $names = array_values(array_filter($this->directory->files()->list(self::HISTORY_DIR), static fn (string $name): bool => str_ends_with($name, '.json')));
        foreach (array_slice($names, 0, max(0, count($names) - self::HISTORY_LIMIT)) as $name) {
            $this->directory->files()->delete(self::HISTORY_DIR.'/'.$name);
        }
    }

    private function open(): JournalRecord
    {
        return $this->current() ?? throw new InvalidState('No operation is open in the journal.');
    }

    private function save(JournalRecord $record): JournalRecord
    {
        $this->directory->write(self::CURRENT, json_encode($record->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");

        return $record;
    }

    private function now(): string
    {
        return CarbonImmutable::now('UTC')->toIso8601ZuluString();
    }
}
