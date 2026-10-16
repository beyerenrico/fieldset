<?php

declare(strict_types=1);

namespace App\Domain\Reports\Filters;

use App\Domain\Submissions\Enums\SubmissionStatus;
use Carbon\CarbonImmutable;

/**
 * Filters of the explorer, exports and saved reports (stored as jsonb in saved_reports.filters and in
 * export options). Days are calendar days in the caller's time zone; `period` is a relative range
 * (DateRange::PERIODS) that wins over from/until. No statuses means every status except draft and void.
 */
final readonly class ReportFilters
{
    /**
     * @param  list<string>  $statuses
     * @param  list<int>  $submitters
     * @param  list<int>  $groups
     * @param  list<FieldFilter>  $fields
     */
    public function __construct(
        public ?string $from = null,
        public ?string $until = null,
        public ?string $period = null,
        public array $statuses = [],
        public array $submitters = [],
        public array $groups = [],
        public array $fields = [],
        public ?string $search = null,
    ) {}

    /** @param  array<array-key, mixed>  $data */
    public static function fromArray(array $data): self
    {
        $day = static fn (mixed $v): ?string => is_string($v) && FieldFilter::isDay($v) ? $v : null;
        $statuses = array_values(array_filter(
            self::strings($data['statuses'] ?? []),
            static fn (string $s): bool => SubmissionStatus::tryFrom($s) instanceof SubmissionStatus && $s !== SubmissionStatus::Draft->value,
        ));
        $fields = [];
        foreach (is_array($data['fields'] ?? null) ? $data['fields'] : [] as $field) {
            $filter = is_array($field) ? FieldFilter::fromArray($field) : null;
            if ($filter instanceof FieldFilter) {
                $fields[] = $filter;
            }
        }
        $search = is_string($data['search'] ?? null) ? mb_substr(trim($data['search']), 0, 100) : '';
        $period = is_string($data['period'] ?? null) && in_array($data['period'], DateRange::PERIODS, true) ? $data['period'] : null;

        return new self(
            $day($data['from'] ?? null), $day($data['until'] ?? null), $period, $statuses,
            self::ids($data['submitters'] ?? []), self::ids($data['groups'] ?? []), $fields, $search === '' ? null : $search,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'from' => $this->from, 'until' => $this->until, 'period' => $this->period, 'statuses' => $this->statuses,
            'submitters' => $this->submitters, 'groups' => $this->groups,
            'fields' => array_map(static fn (FieldFilter $f): array => $f->toArray(), $this->fields), 'search' => $this->search,
        ];
    }

    /** The submitted_at range these filters select, or null for all time. */
    public function dateRange(string $timezone, ?CarbonImmutable $now = null): ?DateRange
    {
        return $this->period !== null
            ? DateRange::period($this->period, $timezone, $now)
            : DateRange::days($this->from, $this->until, $timezone);
    }

    /** @return list<string> statuses to include */
    public function effectiveStatuses(): array
    {
        if ($this->statuses !== []) {
            return $this->statuses;
        }

        return array_values(array_map(
            static fn (SubmissionStatus $s): string => $s->value,
            array_filter(SubmissionStatus::cases(), static fn (SubmissionStatus $s): bool => ! in_array($s, [SubmissionStatus::Draft, SubmissionStatus::Void], true)),
        ));
    }

    /** @return list<string> */
    private static function strings(mixed $values): array
    {
        return is_array($values) ? array_values(array_unique(array_filter($values, is_string(...)))) : [];
    }

    /** @return list<int> */
    private static function ids(mixed $values): array
    {
        $ids = is_array($values) ? array_filter($values, static fn (mixed $v): bool => (is_int($v) && $v > 0) || (is_string($v) && ctype_digit($v))) : [];

        return array_slice(array_values(array_unique(array_map(intval(...), $ids))), 0, 500);
    }
}
