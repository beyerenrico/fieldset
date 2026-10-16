<?php

declare(strict_types=1);

namespace App\Domain\Reports\Saved;

use App\Domain\Reports\Enums\ReportMetric;
use App\Domain\Submissions\Admin\ValueFormatter;

/**
 * Result of SavedReportQuery::run(): one row per group (ordered: time groups chronologically, others by
 * submissions descending) plus the total over all groups. Values are decimal strings — never floats —
 * so a chart or a CSV can use them as they are. At most `reports.saved.max_groups` rows (`truncated`
 * says more groups exist; the total still covers all). Money carries the currencies present and the
 * fraction digits per currency (`scales`). toArray()/fromArray() round-trip it through a cache.
 *
 * @phpstan-import-type ReportRowArray from ReportRow
 *
 * @phpstan-type ReportResultArray array{metric: string, metric_key: ?string, metric_label: string, group_by: ?string, group_label: string, rows: list<ReportRowArray>, total: ReportRowArray, is_money: bool, currency: string, truncated?: bool, currencies?: list<string>, scales?: array<string, int>}
 */
final readonly class ReportResult
{
    /**
     * @param  list<ReportRow>  $rows
     * @param  list<string>  $currencies  currencies of the metric values in scope (sorted)
     * @param  array<string, int>  $scales  currency => fraction digits
     */
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
        public bool $truncated = false,
        public array $currencies = [],
        public array $scales = [],
    ) {}

    /** @param  ReportResultArray  $data */
    public static function fromArray(array $data): self
    {
        return new self(
            ReportMetric::from($data['metric']), $data['metric_key'], $data['metric_label'], $data['group_by'], $data['group_label'],
            array_map(ReportRow::fromArray(...), $data['rows']), ReportRow::fromArray($data['total']), $data['is_money'], $data['currency'],
            $data['truncated'] ?? false, $data['currencies'] ?? [], $data['scales'] ?? [],
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
            'truncated' => $this->truncated, 'currencies' => $this->currencies, 'scales' => $this->scales,
        ];
    }

    /** @return list<string> the currencies when the metric values mix several (sums across them are refused), else [] */
    public function mixedCurrencies(): array
    {
        return count($this->currencies) > 1 ? $this->currencies : [];
    }

    /** Locale display of a row's metric value (with its currency); mixed currencies show a dash and why. */
    public function displayValue(ReportRow $row, ValueFormatter $formatter): string
    {
        if ($row->mixed && $this->metric !== ReportMetric::Count) {
            return ValueFormatter::EMPTY.' ('.__('reports.result.mixed').')';
        }

        return $this->display($row->value($this->metric), $formatter, $row->currency);
    }

    /** @return array{labels: list<string>, values: list<string|null>} chart series (decimal strings) */
    public function chart(): array
    {
        return [
            'labels' => array_map(static fn (ReportRow $row): string => $row->label, $this->rows),
            'values' => array_map(fn (ReportRow $row): ?string => $row->value($this->metric), $this->rows),
        ];
    }

    /**
     * Locale display of a result value: grouped digits; money padded to the scale of its currency (JPY 0,
     * EUR 2, a field with scale 3: 3 digits; averages keep their extra digits) and its currency.
     */
    public function display(?string $value, ValueFormatter $formatter, ?string $currency = null): string
    {
        if ($value === null) {
            return ValueFormatter::EMPTY;
        }
        $currency = $this->isMoney ? ($currency ?? $this->currency) : '';
        $scale = $this->scales[$currency] ?? 2;
        if ($this->isMoney && preg_match('/^(-?\d+)(?:\.(\d*))?$/', $value, $m) === 1 && strlen($m[2] ?? '') < $scale) {
            $value = $m[1].'.'.str_pad($m[2] ?? '', $scale, '0');
        }

        return trim($formatter->decimal($value).' '.$currency);
    }

    public function isTimeSeries(): bool
    {
        return in_array($this->groupBy, ['day', 'week', 'month'], true);
    }
}
