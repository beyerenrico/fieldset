<?php

declare(strict_types=1);

namespace App\Filament\Admin\Widgets;

use App\Domain\Dashboards\DashboardData;
use App\Domain\Identity\Enums\Permission;
use App\Filament\Admin\Widgets\Concerns\InteractsWithDashboard;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Number;

/**
 * KPIs (§8.1 Owner): submissions in the range (visible to the viewer, without drafts and void, like
 * reports), open review tasks, stale drafts, and — for organization managers, who may read the delivery
 * log — documents sent in the range. Each stat says what it counts; the section says how fresh the
 * cached numbers are.
 */
class KpiOverviewWidget extends StatsOverviewWidget
{
    use InteractsWithDashboard;

    protected ?string $pollingInterval = '60s';

    protected function getHeading(): string
    {
        return __('dashboard.kpis.heading');
    }

    protected function getDescription(): ?string
    {
        return $this->freshness('kpis');
    }

    /** @return list<Stat> */
    protected function getStats(): array
    {
        $context = $this->dashboardContext();
        $kpis = $this->dashboardData()->kpis($context);
        $range = $context->range->label();

        $stats = [
            Stat::make(__('dashboard.kpis.submissions'), $this->number($kpis['submissions']))
                ->description(__('dashboard.kpis.submissions_hint').' · '.$range)->icon(Heroicon::OutlinedInboxArrowDown),
            Stat::make(__('dashboard.kpis.pending_reviews'), $this->number($kpis['pending_reviews']))
                ->description(__('dashboard.kpis.pending_reviews_hint'))->icon(Heroicon::OutlinedClipboardDocumentCheck),
            Stat::make(__('dashboard.kpis.stale_drafts'), $this->number($kpis['stale_drafts']))
                ->description(__('dashboard.kpis.stale_drafts_hint', ['days' => DashboardData::staleDraftDays()]))->icon(Heroicon::OutlinedClock),
        ];
        if ($context->can(Permission::OrganizationManage)) {
            $stats[] = Stat::make(__('dashboard.kpis.documents_sent'), $this->number($kpis['documents_sent']))
                ->description(__('dashboard.kpis.documents_sent_hint').' · '.$range)->icon(Heroicon::OutlinedPaperAirplane);
        }

        return $stats;
    }

    private function number(int $value): string
    {
        return (string) Number::format($value, locale: app()->getLocale());
    }
}
