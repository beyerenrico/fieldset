<?php

declare(strict_types=1);

namespace App\Domain\Platform\Instance\Restore;

use App\Domain\Audit\Chain\ChainEpoch;
use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\Model;
use Throwable;

/**
 * What the R64 carry may take from the pre-restore database (third review of plan U17, ADR 0085).
 *
 * Mode `state`: the source descends from the backup, so every difference is something that changed after it (today's
 * state comparison). Mode `audit`: the source's chain shows a restore, rollback or reset epoch that began after the
 * backup (or any such epoch when the backup's time is unknown): it was restored from another backup, and its state says
 * nothing about what administrators did. Then only a timestamp at or after the backup's second (a revocation, an
 * erasure, a deactivation, a soft delete) or an audit entry from that second on that names the row lets a change through
 * (times are whole seconds, M12 gate D8); everything else is counted in `carry_skipped`. Mode `reset`: the source's chain shows a reset after the backup (U23 review A5): its rows
 * are other people's accounts under other IDs (a reset recreates the database), so nothing is carried at all.
 *
 * In both modes the cascade guard keeps a child row whose parent the source no longer has (a deleted form's grants, a
 * deleted group's members): the restore brings the parent back, and the child with it (`cascade_skipped`).
 *
 * Collects the counts while the carry runs (the one mutable object of the carry).
 */
final class CarryScope
{
    public const string STATE = 'state';

    public const string AUDIT = 'audit';

    public const string RESET = 'reset';

    /** @var array<string, int> */
    private array $unaudited = [];

    /** @var array<string, int> */
    private array $cascade = [];

    /** @var array<string, array<string, true>> */
    private array $keys = [];

    private function __construct(public readonly string $mode, private readonly ?CarbonImmutable $since, private readonly ConnectionInterface $source) {}

    /** The backup's time: fieldset's `--backup-created-at`, else the restored database's newest audit entry. */
    public static function detect(ConnectionInterface $source, ConnectionInterface $target, ?CarbonImmutable $backupCreatedAt): self
    {
        $since = $backupCreatedAt ?? self::newestEntry($target);
        $epochs = $source->table('audit_chain_epochs')->whereIn('reason', ChainEpoch::REASONS);
        if ($since instanceof CarbonImmutable) {
            $epochs->where('started_at', '>=', $since->utc()->format('Y-m-d H:i:sP')); // whole seconds: the backup's own second counts (M12 gate D8)
        }

        $mode = match (true) {
            (clone $epochs)->where('reason', 'reset')->exists() => self::RESET,
            $epochs->exists() => self::AUDIT,
            default => self::STATE,
        };

        return new self($mode, $since, $source);
    }

    public function audited(): bool
    {
        return $this->mode !== self::STATE;
    }

    /** A reset after the backup: the source holds other accounts, nothing may be carried (U23 review A5). */
    public function afterReset(): bool
    {
        return $this->mode === self::RESET;
    }

    /**
     * The subjects (`subject_id`) of the source's audit entries after the backup: events of `$model`, optionally only
     * those whose logged attributes include `$attribute`. Null in mode state (no restriction).
     *
     * @param  class-string<Model>  $model
     * @param  list<string>  $events
     * @return array<string, true>|null
     */
    public function subjects(string $model, array $events, ?string $attribute = null): ?array
    {
        if (! $this->audited()) {
            return null;
        }
        if (! $this->since instanceof CarbonImmutable) {
            return [];
        }
        $query = $this->source->table('activity_log')->where('log_name', 'audit')->where('subject_type', new $model()->getMorphClass())
            ->whereIn('event', $events)->where('created_at', '>=', $this->since->utc()->format('Y-m-d H:i:s'));
        if ($attribute !== null) {
            $query->whereRaw("(properties::jsonb -> 'attributes' -> ?) IS NOT NULL", [$attribute]);
        }

        return array_fill_keys(array_map(strval(...), $query->pluck('subject_id')->filter()->all()), true);
    }

    /**
     * Whether a change may be carried: always in mode state; in mode audit when the source's timestamp is after the
     * backup or an audit entry after it names `$key`. A refusal is counted under `$item`.
     *
     * @param  array<string, true>|null  $subjects
     */
    public function permits(string $item, ?array $subjects, int|string|null $key, mixed $timestamp = null): bool
    {
        if (! $this->audited() || $this->after($timestamp) || ($key !== null && isset($subjects[(string) $key]))) {
            return true;
        }
        $this->unaudited[$item] = ($this->unaudited[$item] ?? 0) + 1;

        return false;
    }

    /**
     * Whether a child row's parent is gone from the source (`[table => id]`, null ids ignored): then it is kept and
     * counted under `$item`.
     *
     * @param  array<string, int|string|null>  $parents
     */
    public function orphaned(string $item, array $parents): bool
    {
        foreach ($parents as $table => $id) {
            if ($id !== null && ! isset($this->ids($table)[(string) $id])) {
                $this->cascade[$item] = ($this->cascade[$item] ?? 0) + 1;

                return true;
            }
        }

        return false;
    }

    /** @return array{carry_mode: string, carry_skipped: array<string, int>, cascade_skipped: array<string, int>} */
    public function report(): array
    {
        ksort($this->unaudited);
        ksort($this->cascade);

        return ['carry_mode' => $this->mode, 'carry_skipped' => $this->unaudited, 'cascade_skipped' => $this->cascade];
    }

    private function after(mixed $timestamp): bool
    {
        if ($timestamp === null || ! $this->since instanceof CarbonImmutable) {
            return false;
        }

        try {
            return CarbonImmutable::parse((string) $timestamp, 'UTC')->greaterThanOrEqualTo($this->since);
        } catch (Throwable) {
            return false;
        }
    }

    /** @return array<string, true> the ids of a source table */
    private function ids(string $table): array
    {
        return $this->keys[$table] ??= array_fill_keys(array_map(strval(...), $this->source->table($table)->pluck('id')->all()), true);
    }

    private static function newestEntry(ConnectionInterface $target): ?CarbonImmutable
    {
        $newest = $target->table('activity_log')->max('created_at');

        return is_string($newest) ? CarbonImmutable::parse($newest, 'UTC') : null;
    }
}
