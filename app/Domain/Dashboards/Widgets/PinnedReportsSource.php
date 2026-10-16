<?php

declare(strict_types=1);

namespace App\Domain\Dashboards\Widgets;

use App\Domain\Dashboards\DashboardContext;
use App\Domain\Dashboards\Pins\DashboardPins;
use App\Domain\Reports\Models\SavedReport;
use App\Filament\Admin\Widgets\PinnedReportWidget;
use Filament\Widgets\WidgetConfiguration;

/**
 * Saved reports the viewer pinned (§8.1 v1.0, ADR 0034): one PinnedReportWidget per pin the viewer may
 * still view, in pin order, after the role widgets (config('dashboards.widget_sources')).
 */
final readonly class PinnedReportsSource implements DashboardWidgetSource
{
    public function __construct(private DashboardPins $pins) {}

    /** @return list<WidgetConfiguration> */
    public function widgetsFor(DashboardContext $context): array
    {
        return array_map(
            static fn (SavedReport $report): WidgetConfiguration => PinnedReportWidget::make(['savedReportId' => $report->id]),
            $this->pins->visibleReports($context->user),
        );
    }
}
