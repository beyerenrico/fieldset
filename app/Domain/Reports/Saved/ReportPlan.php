<?php

declare(strict_types=1);

namespace App\Domain\Reports\Saved;

use App\Domain\Reports\Enums\ReportMetric;
use App\Domain\Reports\Schema\FieldCatalog;
use App\Domain\Reports\Schema\ReportField;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * What SavedReportQuery needs to aggregate one saved report for one viewer: the selected submissions
 * (ReportScope, visible to the viewer), the grouping, the metric and its field, the time zone, and —
 * for a money field whose currency differs between form versions — the currency of each version.
 */
final readonly class ReportPlan
{
    /** @param  array<int, string>  $currencyByVersion  form version id => currency; empty when the field has one currency */
    public function __construct(
        public FieldCatalog $catalog,
        public ReportGrouping $grouping,
        public ReportMetric $metric,
        public ?ReportField $metricField,
        public string $timezone,
        public QueryBuilder $submissions,
        public array $currencyByVersion,
    ) {}

    public function isMoney(): bool
    {
        return $this->metricField?->isMoney() ?? false;
    }

    /** @return array<string, int> currency => fraction digits, for every currency the field has */
    public function scales(): array
    {
        if (! $this->metricField instanceof ReportField || ! $this->isMoney()) {
            return [];
        }
        $scales = [];
        foreach (array_unique([$this->metricField->currency(), ...array_values($this->metricField->currencies())]) as $currency) {
            $scales[$currency] = $this->metricField->scaleFor($currency);
        }

        return $scales;
    }

    /**
     * Currency of a group from the versions of its values ("1,3" from string_agg), or null when the
     * versions use different currencies (`mixed`).
     *
     * @return array{0: ?string, 1: bool} currency, mixed
     */
    public function currencyOf(?string $versions): array
    {
        if (! $this->isMoney()) {
            return [null, false];
        }
        $currencies = $this->currencies($versions);

        return count($currencies) > 1 ? [null, true] : [$currencies[0] ?? null, false];
    }

    /** @return list<string> distinct currencies of the given version ids (sorted); the field's currency when uniform */
    public function currencies(?string $versions): array
    {
        if (! $this->isMoney()) {
            return [];
        }
        if ($this->currencyByVersion === []) {
            return [(string) $this->metricField?->currency()];
        }
        $currencies = [];
        foreach (array_filter(explode(',', (string) $versions), is_numeric(...)) as $versionId) {
            $currencies[] = $this->currencyByVersion[(int) $versionId] ?? (string) $this->metricField?->currency();
        }
        $currencies = array_values(array_unique($currencies));
        sort($currencies);

        return $currencies;
    }
}
