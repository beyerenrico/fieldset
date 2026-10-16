<?php

declare(strict_types=1);

namespace App\Filament\Admin\Widgets;

use App\Filament\Admin\Resources\Submissions\SubmissionResource;
use App\Filament\Admin\Resources\Workflows\WorkflowResource;
use App\Filament\Admin\Widgets\Concerns\InteractsWithDashboard;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

/** Workflow failures (§8.1 Owner): failed runs finished in the range (default: the last 7 days). */
class WorkflowFailuresWidget extends TableWidget
{
    use InteractsWithDashboard;

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->heading(__('dashboard.workflow_failures.heading'))
            ->description(fn (): string => $this->dashboardContext()->range->label())
            ->records(fn (): array => self::keyed($this->dashboardData()->workflowFailures($this->dashboardContext()), 'id'))
            ->paginated(false)
            ->emptyStateHeading(__('dashboard.workflow_failures.empty'))
            ->recordUrl(fn (array $record): string => WorkflowResource::getUrl('edit', ['record' => $record['workflow_id']]))
            ->columns([
                TextColumn::make('workflow')->label(__('dashboard.columns.workflow')),
                TextColumn::make('error')->label(__('dashboard.columns.error'))->placeholder('—')->wrap(),
                TextColumn::make('finished_at')->label(__('dashboard.columns.failed_at'))->dateTime()
                    ->timezone(SubmissionResource::displayTimezone()),
            ]);
    }
}
