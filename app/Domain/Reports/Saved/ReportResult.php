<?php

declare(strict_types=1);

namespace App\Domain\Reports\Saved;

use App\Domain\Reports\Enums\ReportMetric;
use App\Domain\Submissions\Admin\ValueFormatter;

/**
 * Result of SavedReportQuery::run(): one row per group (ordered: time groups chronologically, others by
 * submissions descending) plus the total over all groups. Values are decimal strings — never floats —
 * so a chart or a CSV can use them as they are. toArray()/fromArray() round-trip it through a cache.
 *
 * @phpstan-import-type ReportRowArray from ReportRow
 *
 * @phpstan-type ReportResultArray array{metric: string, metric_key: ?string, metric_label: string, group_by: ?string, group_label: string, rows: list<ReportRowArray>, total: ReportRowArray, is_money: bool, currency: string}
 */
final readonly class ReportResult
{
    /** @param  list<ReportRow>  $rows */
    public function __construct(
        public ReportMetric $metric,
        public ?string $metricKey,
        public string $metricLabel,
        public ?string $groupBy,
        public string $groupLabel,
        public array $rows,
        public ReportRow $total,
        public bool $isMoney = false,
        public string $currency = '',
    ) {}

    /** @param  ReportResultArray  $data */
    public static function fromArray(array $data): self
    {
        return new self(
            ReportMetric::from($data['metric']), $data['metric_key'], $data['metric_label'], $data['group_by'], $data['group_label'],
            array_map(ReportRow::fromArray(...), $data['rows']), ReportRow::fromArray($data['total']), $data['is_money'], $data['currency'],
        );
    }

    /** @return ReportResultArray */
    public function toArray(): array
    {
        return [
            'metric' => $this->metric->value, 'metric_key' => $this->metricKey, 'metric_label' => $this->metricLabel,
            'group_by' => $this->groupBy, 'group_label' => $this->groupLabel,
            'rows' => array_map(static fn (ReportRow $row): array => $row->toArray(), $this->rows), 'total' => $this->total->toArray(),
            'is_money' => $this->isMoney, 'currency' => $this->currency,
        ];
    }

    /** @return array{labels: list<string>, values: list<string|null>} chart series (decimal strings) */
    public function chart(): array
    {
        return [
            'labels' => array_map(static fn (ReportRow $row): string => $row->label, $this->rows),
            'values' => array_map(fn (ReportRow $row): ?string => $row->value($this->metric), $this->rows),
        ];
    }

    /** Locale display of a result value: grouped digits; money with at least two decimals and its currency. */
    public function display(?string $value, ValueFormatter $formatter): string
    {
        if ($value === null) {
            return ValueFormatter::EMPTY;
        }
        if ($this->isMoney && preg_match('/^(-?\d+)(?:\.(\d*))?$/', $value, $m) === 1 && strlen($m[2] ?? '') < 2) {
            $value = $m[1].'.'.str_pad($m[2] ?? '', 2, '0');
        }

        return trim($formatter->decimal($value).' '.($this->isMoney ? $this->currency : ''));
    }

    public function isTimeSeries(): bool
    {
        return in_array($this->groupBy, ['day', 'week', 'month'], true);
    }
}
