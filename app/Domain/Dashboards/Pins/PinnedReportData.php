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
        $widget = implode(':', ['pinned_report', $report->id, $report->updated_at->getTimestamp(), app()->getLocale()]);

        return ReportResult::fromArray($this->cache->remember(
            $context, $widget, CacheScope::User,
            fn (): array => $this->query->run($report, self::range($context->range), $context->user)->toArray(),
        ));
    }

    /** The dashboard filter (whole days in the viewer's time zone) as a report range [from, until). */
    public static function range(DashboardRange $range): DateRange
    {
        return new DateRange($range->startUtc(), $range->endUtc());
    }
}
