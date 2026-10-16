<?php

declare(strict_types=1);

namespace App\Filament\Admin\Widgets;

use App\Domain\Submissions\Enums\SubmissionStatus;
use App\Filament\Admin\Resources\Submissions\SubmissionResource;
use App\Filament\Admin\Widgets\Concerns\InteractsWithDashboard;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

/** Recent submissions (§8.1 Reviewer, Viewer): submitted in the range, only those the user may see (§8.6). */
class RecentSubmissionsWidget extends TableWidget
{
    use InteractsWithDashboard;

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->heading(__('dashboard.recent_submissions.heading'))
            ->description(fn (): string => $this->rangeAndFreshness('recent_submissions'))
            ->records(fn (): array => self::keyed($this->dashboardData()->recentSubmissions($this->dashboardContext()), 'uuid'))
            ->paginated(false)
            ->emptyStateHeading(__('dashboard.recent_submissions.empty'))
            ->recordUrl(fn (array $record): string => SubmissionResource::getUrl('view', ['record' => $record['uuid']]))
            ->columns([
                TextColumn::make('number')->label(__('dashboard.columns.number'))->placeholder('—'),
                TextColumn::make('form')->label(__('dashboard.columns.form')),
                TextColumn::make('status')->label(__('dashboard.columns.status'))->badge()
                    ->formatStateUsing(fn (string $state): string => SubmissionStatus::from($state)->label())
                    ->color(fn (string $state): string => SubmissionResource::statusColor(SubmissionStatus::from($state))),
                TextColumn::make('submitter')->label(__('dashboard.columns.submitter'))->placeholder('—'),
                TextColumn::make('submitted_at')->label(__('dashboard.columns.submitted_at'))->isoDateTime('L LT')
                    ->timezone(SubmissionResource::displayTimezone()),
            ]);
    }
}
