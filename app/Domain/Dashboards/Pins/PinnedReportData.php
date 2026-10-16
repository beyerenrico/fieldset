<?php

declare(strict_types=1);

namespace App\Domain\Dashboards\Pins;

use App\Domain\Dashboards\CacheScope;
use App\Domain\Dashboards\DashboardCache;
use App\Domain\Dashboards\DashboardContext;
use App\Domain\Dashboards\DateRange as DashboardRange;
use App\Domain\Reports\Filters\DateRange;
use App\Domain\Reports\Models\SavedReport;
use App\Domain\Reports\Saved\ReportResult;
use App\Domain\Reports\SavedReportQuery;
use App\Support\Dates\LocalDate;
use Carbon\CarbonImmutable;

/**
 * The result of a pinned saved report on the dashboard (ADR 0034): SavedReportQuery for the viewer, over
 * the dashboard date range intersected with the report's own date filter, cached per user (DashboardCache,
 * CacheScope::User). Callers authorize first (DashboardPins::visibleReport) so a cached result is never
 * shown after access was lost. The cache key holds the report's updated_at, so edits show at once.
 */
final readonly class PinnedReportData
{
    public function __construct(private SavedReportQuery $query, private DashboardCache $cache) {}

    public function result(DashboardContext $context, SavedReport $report): ReportResult
    {
        return ReportResult::fromArray($this->cache->remember(
            $context, $this->key($report), CacheScope::User,
            fn (): array => $this->query->run($report, self::range($context->range), $context->user)->toArray(),
        ));
    }

    /** When the cached result was computed (null when not cached). */
    public function computedAt(DashboardContext $context, SavedReport $report): ?CarbonImmutable
    {
        return $this->cache->computedAt($context, $this->key($report), CacheScope::User);
    }

    /**
     * The range a pinned report actually covers: the dashboard range ∩ the report's own date filter,
     * labelled in the viewer's time zone and locale; `empty` when they do not overlap (then `report`
     * names the report's own period).
     *
     * @return array{label: string, empty: bool, report: string}
     */
    public static function usedRange(SavedReport $report, DashboardRange $dashboard): array
    {
        $filters = $report->reportFilters();
        $own = $filters->dateRange(SavedReportQuery::timezone($report));
        $used = self::range($dashboard)->intersect($own);
        $label = static fn (CarbonImmutable $from, CarbonImmutable $until): string => LocalDate::range(
            $from->setTimezone($dashboard->timezone), $until->subSecond()->max($from)->setTimezone($dashboard->timezone),
        );
        $day = static fn (?string $day): string => $day === null ? '…' : LocalDate::date(CarbonImmutable::parse($day));
        $reportLabel = match (true) {
            $filters->period !== null => (string) __('reports.period.'.$filters->period),
            $filters->from !== null && $filters->until !== null => LocalDate::range(CarbonImmutable::parse($filters->from), CarbonImmutable::parse($filters->until)),
            $own instanceof DateRange => $day($filters->from).' – '.$day($filters->until),
            default => (string) __('reports.period.all'),
        };

        return ['label' => $label($used->from, $used->until), 'empty' => ! $used->until->greaterThan($used->from), 'report' => $reportLabel];
    }

    private function key(SavedReport $report): string
    {
        return implode(':', ['pinned_report', $report->id, $report->updated_at->getTimestamp(), app()->getLocale()]);
    }

    /** The dashboard filter (whole days in the viewer's time zone) as a report range [from, until). */
    public static function range(DashboardRange $range): DateRange
    {
        return new DateRange($range->startUtc(), $range->endUtc());
    }
}
