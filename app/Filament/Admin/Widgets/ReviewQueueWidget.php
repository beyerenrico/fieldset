<?php

declare(strict_types=1);

namespace App\Filament\Admin\Widgets;

use App\Filament\Admin\Resources\Submissions\SubmissionResource;
use App\Filament\Admin\Widgets\Concerns\InteractsWithDashboard;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

/** Review queue (§8.1 Reviewer): open review tasks the user may act on, most urgent first. */
class ReviewQueueWidget extends TableWidget
{
    use InteractsWithDashboard;

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        $timezone = SubmissionResource::displayTimezone();

        return $table
            ->heading(__('dashboard.review_queue.heading'))
            ->records(fn (): array => self::keyed($this->dashboardData()->reviewQueue($this->dashboardContext()), 'id'))
            ->paginated(false)
            ->emptyStateHeading(__('dashboard.review_queue.empty'))
            ->recordUrl(fn (array $record): string => SubmissionResource::getUrl('view', ['record' => $record['submission_uuid']]))
            ->columns([
                TextColumn::make('number')->label(__('dashboard.columns.number'))->placeholder('—'),
                TextColumn::make('form')->label(__('dashboard.columns.form')),
                TextColumn::make('due_at')->label(__('dashboard.columns.due_at'))->dateTime()->timezone($timezone)->placeholder('—')
                    // Overdue is said in words and with an icon, not by colour alone.
                    ->color(fn (array $record): ?string => $record['overdue'] ? 'danger' : null)
                    ->icon(fn (array $record): ?string => $record['overdue'] ? 'heroicon-o-exclamation-triangle' : null)
                    ->description(fn (array $record): ?string => $record['overdue'] ? (string) __('dashboard.review_queue.overdue') : null),
                TextColumn::make('created_at')->label(__('dashboard.columns.created_at'))->since(),
            ]);
    }
}
