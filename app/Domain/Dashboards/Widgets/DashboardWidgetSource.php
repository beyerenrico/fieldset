<?php

declare(strict_types=1);

namespace App\Domain\Dashboards\Widgets;

use App\Domain\Dashboards\DashboardContext;
use Filament\Widgets\Widget;
use Filament\Widgets\WidgetConfiguration;

/**
 * Supplies dashboard widgets for a viewer (§8.1). Sources are listed in `config('dashboards.widget_sources')`
 * and asked in that order; the dashboard shows the union (first occurrence of a class wins, configured
 * instances — WidgetConfiguration — are kept as they are, so one widget class can appear several times).
 *
 * Extension point: pinned saved reports (§8.4) are another source that returns one configured widget per
 * pinned report, e.g. `PinnedReportWidget::make(['savedReportId' => $id])`. A widget class served by a
 * source must also deny direct Livewire access in `canView()` when the source would not offer it
 * (see DashboardWidgetRegistry::offers()).
 */
interface DashboardWidgetSource
{
    /** @return list<class-string<Widget>|WidgetConfiguration> */
    public function widgetsFor(DashboardContext $context): array;
}
