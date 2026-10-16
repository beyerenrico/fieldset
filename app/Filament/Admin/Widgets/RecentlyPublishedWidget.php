<?php

declare(strict_types=1);

namespace App\Filament\Admin\Widgets;

use App\Filament\Admin\Resources\Forms\FormResource;
use App\Filament\Admin\Resources\Submissions\SubmissionResource;
use App\Filament\Admin\Widgets\Concerns\InteractsWithDashboard;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

/** Recently published versions (§8.1 Designer): versions published in the range, newest first. */
class RecentlyPublishedWidget extends TableWidget
{
    use InteractsWithDashboard;

    public function table(Table $table): Table
    {
        return $table
            ->heading(__('dashboard.recently_published.heading'))
            ->description(fn (): string => $this->rangeAndFreshness('recently_published'))
            ->records(fn (): array => self::keyed($this->dashboardData()->recentlyPublished($this->dashboardContext()), 'id'))
            ->paginated(false)
            ->emptyStateHeading(__('dashboard.recently_published.empty'))
            ->recordUrl(fn (array $record): string => FormResource::getUrl('edit', ['record' => $record['form_id']]))
            ->columns([
                TextColumn::make('form')->label(__('dashboard.columns.form')),
                TextColumn::make('version')->label(__('dashboard.columns.version'))->prefix('v'),
                TextColumn::make('publisher')->label(__('dashboard.columns.publisher'))->placeholder('—'),
                TextColumn::make('published_at')->label(__('dashboard.columns.published_at'))->isoDateTime('L LT')
                    ->timezone(SubmissionResource::displayTimezone()),
            ]);
    }
}
