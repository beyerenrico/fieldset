<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages;

use App\Domain\Dashboards\DashboardContext;
use App\Domain\Dashboards\DateRange;
use App\Domain\Dashboards\Widgets\DashboardWidgetRegistry;
use App\Domain\Identity\Models\Organization;
use App\Domain\Identity\Models\User;
use App\Filament\Admin\Resources\Submissions\SubmissionResource;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Pages\Dashboard\Concerns\HasFiltersForm;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Widgets\AccountWidget;
use Filament\Widgets\Widget;
use Filament\Widgets\WidgetConfiguration;

/**
 * Role-aware dashboard (§8.1): the widgets come from the configured DashboardWidgetSources for the
 * current user; a date-range filter (persisted per tenant in the session and the URL) drives them.
 */
class Dashboard extends BaseDashboard
{
    use HasFiltersForm;

    public function filtersForm(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()
                ->schema([
                    DatePicker::make('startDate')->label(__('dashboard.filters.start'))
                        ->maxDate(fn (Get $get): mixed => $get('endDate') ?: now()),
                    DatePicker::make('endDate')->label(__('dashboard.filters.end'))
                        ->minDate(fn (Get $get): mixed => $get('startDate') ?: null)->maxDate(now()),
                ])
                ->description(fn (): string => __('dashboard.filters.hint', [
                    'days' => (int) config('dashboards.default_range_days', 7),
                    'range' => DateRange::fromFilters($this->filters, SubmissionResource::displayTimezone())->label(),
                ]))
                ->columns(2)
                ->columnSpanFull(),
        ]);
    }

    /** @return array<class-string<Widget>|WidgetConfiguration> */
    public function getWidgets(): array
    {
        $user = Filament::auth()->user();
        if (! $user instanceof User || ! Filament::getTenant() instanceof Organization) {
            return [AccountWidget::class];
        }

        $context = DashboardContext::current($user, DateRange::fromFilters($this->filters, SubmissionResource::displayTimezone()));

        return [AccountWidget::class, ...app(DashboardWidgetRegistry::class)->widgetsFor($context)];
    }
}
