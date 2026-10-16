<?php

declare(strict_types=1);

namespace App\Domain\Dashboards\Widgets;

use App\Domain\Dashboards\DashboardContext;
use App\Domain\Identity\Enums\Permission;
use App\Filament\Admin\Widgets\DraftFormsWidget;
use App\Filament\Admin\Widgets\KpiOverviewWidget;
use App\Filament\Admin\Widgets\RecentlyPublishedWidget;
use App\Filament\Admin\Widgets\RecentSubmissionsWidget;
use App\Filament\Admin\Widgets\ReviewQueueWidget;
use App\Filament\Admin\Widgets\SubmissionsChartWidget;
use App\Filament\Admin\Widgets\WorkflowFailuresWidget;
use Filament\Widgets\Widget;

/**
 * Role-aware default dashboards (§8.1), resolved from permissions so custom and combined roles work:
 *  - organization managers (Owner, Admin): KPIs, submissions over time per form, workflow failures;
 *  - otherwise the union of: reviewers — review queue and recent submissions they may see;
 *    designers — draft forms and recently published versions; viewers (view all) — KPIs and recent
 *    submissions. Anyone else who may see some submissions gets the recent submissions.
 */
final class RoleWidgetSource implements DashboardWidgetSource
{
    /** Display order of all role widgets. */
    public const array ORDER = [
        KpiOverviewWidget::class,
        SubmissionsChartWidget::class,
        ReviewQueueWidget::class,
        RecentSubmissionsWidget::class,
        WorkflowFailuresWidget::class,
        DraftFormsWidget::class,
        RecentlyPublishedWidget::class,
    ];

    /** @return list<class-string<Widget>> */
    public function widgetsFor(DashboardContext $context): array
    {
        if ($context->can(Permission::OrganizationManage)) {
            return [KpiOverviewWidget::class, SubmissionsChartWidget::class, WorkflowFailuresWidget::class];
        }

        $widgets = [];
        if ($context->can(Permission::SubmissionsViewAll)) {
            $widgets[] = KpiOverviewWidget::class;
            $widgets[] = RecentSubmissionsWidget::class;
        }
        if ($context->can(Permission::SubmissionsReview)) {
            $widgets[] = ReviewQueueWidget::class;
            $widgets[] = RecentSubmissionsWidget::class;
        }
        if ($context->can(Permission::FormsManage)) {
            $widgets[] = DraftFormsWidget::class;
            $widgets[] = RecentlyPublishedWidget::class;
        }
        if ($widgets === [] && $this->seesSomeSubmissions($context)) {
            $widgets[] = RecentSubmissionsWidget::class;
        }

        return array_values(array_filter(self::ORDER, fn (string $widget): bool => in_array($widget, $widgets, true)));
    }

    private function seesSomeSubmissions(DashboardContext $context): bool
    {
        return array_any([Permission::SubmissionsViewOwnForms, Permission::SubmissionsViewAssigned, Permission::SubmissionsViewOwn], fn (Permission $permission): bool => $context->can($permission));
    }
}
