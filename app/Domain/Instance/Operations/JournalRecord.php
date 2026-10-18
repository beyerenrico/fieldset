<?php

declare(strict_types=1);

namespace App\Domain\Instance\Operations;

use App\Domain\Instance\Output\ResultStatus;
use App\Domain\Instance\State\InvalidState;
use App\Domain\Instance\State\SchemaVersion;
use Closure;

/**
 * One operation's journal document (R54): phases with their resume data, events (lock takeover, resume) and,
 * once finished, the outcome. Immutable; keys from a newer minor version are carried through unchanged.
 *
 * @phpstan-type Phase array{name: string, attempt: int, status: string, changes: bool, started_at: string, finished_at: string|null, data: array<string, mixed>, error?: string}
 * @phpstan-type Event array{at: string, type: string, data: array<string, mixed>}
 */
final readonly class JournalRecord
{
    public const string SCHEMA_VERSION = '1.0';

    /** @param  array<string, mixed>  $document */
    private function __construct(private array $document) {}

    /** @param  array<string, mixed>  $context */
    public static function start(string $id, string $operation, ?int $hostUid, array $context, string $now): self
    {
        return self::fromArray([
            'schema_version' => self::SCHEMA_VERSION, 'id' => $id, 'operation' => $operation, 'host_uid' => $hostUid,
            'started_at' => $now, 'finished_at' => null, 'outcome' => null, 'context' => $context,
            'phases' => [], 'events' => [], 'result' => [],
        ]);
    }

    /**
     * @param  array<string, mixed>  $document
     *
     * @throws InvalidState
     */
    public static function fromArray(array $document): self
    {
        SchemaVersion::parse($document['schema_version'] ?? null, 'operation journal')
            ->assertReadableBy(SchemaVersion::of(self::SCHEMA_VERSION), 'operation journal');
        foreach (['id', 'operation', 'started_at'] as $key) {
            if (! is_string($document[$key] ?? null) || $document[$key] === '') {
                throw new InvalidState("The operation journal is invalid: {$key} is missing.");
            }
        }
        foreach (['phases', 'events'] as $key) {
            if (! is_array($document[$key] ?? null) || ! array_is_list($document[$key])) {
                throw new InvalidState("The operation journal is invalid: {$key} must be a list.");
            }
        }

        return new self($document);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->document;
    }

    public function id(): string
    {
        return (string) $this->document['id'];
    }

    public function operation(): string
    {
        return (string) $this->document['operation'];
    }

    public function hostUid(): ?int
    {
        return is_int($this->document['host_uid'] ?? null) ? $this->document['host_uid'] : null;
    }

    public function startedAt(): string
    {
        return (string) $this->document['started_at'];
    }

    public function finishedAt(): ?string
    {
        return is_string($this->document['finished_at'] ?? null) ? $this->document['finished_at'] : null;
    }

    public function outcome(): ?ResultStatus
    {
        return is_string($this->document['outcome'] ?? null) ? ResultStatus::tryFrom($this->document['outcome']) : null;
    }

    public function isFinished(): bool
    {
        return $this->finishedAt() !== null;
    }

    /** @return array<string, mixed> */
    public function context(): array
    {
        return is_array($this->document['context'] ?? null) ? $this->document['context'] : [];
    }

    /** @return list<Phase> */
    public function phases(): array
    {
        return $this->document['phases'];
    }

    /** @return list<Event> */
    public function events(): array
    {
        return $this->document['events'];
    }

    public function lastStartedPhase(): ?string
    {
        $phases = $this->phases();

        return $phases === [] ? null : $phases[array_key_last($phases)]['name'];
    }

    public function lastCompletedPhase(): ?string
    {
        $completed = array_filter($this->phases(), static fn (array $phase): bool => $phase['status'] === 'completed');

        return $completed === [] ? null : $completed[array_key_last($completed)]['name'];
    }

    /** Whether a phase that changes the instance has started (R69: later failures are not "before any change"). */
    public function hasChanges(): bool
    {
        return array_any($this->phases(), static fn (array $phase): bool => ($phase['changes'] ?? true) === true);
    }

    /**
     * The data recorded by the last completed run of a phase.
     *
     * @return array<string, mixed>
     */
    public function phaseData(string $phase): array
    {
        $completed = array_filter($this->phases(), static fn (array $entry): bool => $entry['name'] === $phase && $entry['status'] === 'completed');

        return $completed === [] ? [] : $completed[array_key_last($completed)]['data'];
    }

    public function hasCompleted(string $phase): bool
    {
        return array_any($this->phases(), static fn (array $entry): bool => $entry['name'] === $phase && $entry['status'] === 'completed');
    }

    /** Whether any attempt of the phase started, whatever became of it (completed, failed, or cut off). */
    public function hasStarted(string $phase): bool
    {
        return array_any($this->phases(), static fn (array $entry): bool => $entry['name'] === $phase);
    }

    /**
     * The data of the last attempt of a phase, whatever its status: what was known when it started, plus its result
     * if it completed (e.g. the failure a `config set` revert was undoing).
     *
     * @return array<string, mixed>
     */
    public function lastAttemptData(string $phase): array
    {
        $attempts = array_filter($this->phases(), static fn (array $entry): bool => $entry['name'] === $phase);

        return $attempts === [] ? [] : $attempts[array_key_last($attempts)]['data'];
    }

    /**
     * What a resumed run needs: the context, then each phase's data in order (later values win), then the
     * result data of a finished operation.
     *
     * @return array<string, mixed>
     */
    public function resumeData(): array
    {
        $data = $this->context();
        foreach ($this->phases() as $phase) {
            $data = [...$data, ...$phase['data']];
        }
        $result = $this->document['result'] ?? [];

        return is_array($result) ? [...$data, ...$result] : $data;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  bool  $changes  whether the phase changes the instance (false for doctor, verify, pull)
     */
    public function withPhaseStarted(string $phase, array $data, string $now, bool $changes = true): self
    {
        $attempt = count(array_filter($this->phases(), static fn (array $entry): bool => $entry['name'] === $phase)) + 1;
        $entry = ['name' => $phase, 'attempt' => $attempt, 'status' => 'started', 'changes' => $changes, 'started_at' => $now, 'finished_at' => null, 'data' => $data];

        return $this->with('phases', [...$this->phases(), $entry]);
    }

    /** @param  array<string, mixed>  $data */
    public function withPhaseCompleted(string $phase, array $data, string $now): self
    {
        return $this->withLastPhase($phase, static fn (array $entry): array => [...$entry, 'status' => 'completed', 'finished_at' => $now, 'data' => [...$entry['data'], ...$data]]);
    }

    public function withPhaseFailed(string $phase, string $error, string $now): self
    {
        return $this->withLastPhase($phase, static fn (array $entry): array => [...$entry, 'status' => 'failed', 'finished_at' => $now, 'error' => $error]);
    }

    /** @param  array<string, mixed>  $data */
    public function withEvent(string $type, array $data, string $now): self
    {
        return $this->with('events', [...$this->events(), ['at' => $now, 'type' => $type, 'data' => $data]]);
    }

    /** @param  array<string, mixed>  $data */
    public function withOutcome(ResultStatus $outcome, array $data, string $now): self
    {
        return $this->with('outcome', $outcome->value)->with('finished_at', $now)->with('result', $data);
    }

    /**
     * The record with the string values of `$keys` in the context and in every event's data passed through
     * `$replace` (null drops the value), e.g. personal inputs replaced by digests before archiving.
     *
     * @param  list<string>  $keys
     * @param  Closure(string): ?string  $replace
     */
    public function withValuesReplaced(array $keys, Closure $replace): self
    {
        $map = static function (array $data) use ($keys, $replace): array {
            foreach ($keys as $key) {
                if (is_string($data[$key] ?? null)) {
                    $data[$key] = $replace($data[$key]);
                }
            }

            return $data;
        };
        $events = array_map(static fn (array $event): array => [...$event, 'data' => $map($event['data'])], $this->events());

        return $this->with('context', $map($this->context()))->with('events', $events);
    }

    /** @param  callable(Phase): Phase  $change */
    private function withLastPhase(string $phase, callable $change): self
    {
        $phases = $this->phases();
        for ($index = count($phases) - 1; $index >= 0; $index--) {
            if ($phases[$index]['name'] === $phase) {
                $phases[$index] = $change($phases[$index]);

                return $this->with('phases', $phases);
            }
        }

        throw new InvalidState("The operation journal has no phase [{$phase}] to update.");
    }

    private function with(string $key, mixed $value): self
    {
        $document = $this->document;
        $document[$key] = $value;
        $document['schema_version'] = (string) SchemaVersion::parse($document['schema_version'], 'operation journal')
            ->max(SchemaVersion::of(self::SCHEMA_VERSION));

        return new self($document);
    }
}
