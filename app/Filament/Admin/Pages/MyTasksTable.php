<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages;

use App\Domain\Identity\Models\User;
use App\Domain\Workflows\Models\Task;
use App\Filament\Admin\Resources\Submissions\Actions\ReviewActions;
use App\Filament\Admin\Resources\Submissions\SubmissionResource;
use Filament\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/** Table of the "My tasks" page: open review tasks with approve / request changes. */
final class MyTasksTable
{
    public static function configure(Table $table): Table
    {
        $open = static fn (Task $record): ?Task => $record->isOpen() ? $record : null;

        return $table
            ->query(fn (): Builder => self::query())
            ->defaultSort('created_at', 'desc')
            ->poll('30s')
            ->emptyStateHeading(__('workflows.tasks.empty'))
            ->columns([
                TextColumn::make('submission.number')->label(__('workflows.tasks.submission'))->placeholder('—')
                    ->url(fn (Task $record): string => SubmissionResource::getUrl('view', ['record' => $record->submission_uuid])),
                TextColumn::make('submission.form.name')->label(__('workflows.tasks.form')),
                TextColumn::make('assignee')->label(__('workflows.tasks.assignee'))
                    ->state(fn (Task $record): ?string => $record->assigneeUser->name ?? $record->assigneeGroup?->name),
                TextColumn::make('due_at')->label(__('workflows.tasks.due_at'))->dateTime()->timezone(SubmissionResource::displayTimezone())
                    ->sortable()->placeholder('—')
                    // Overdue is said in words and with an icon, not by colour alone.
                    ->color(fn (Task $record): ?string => $record->isOverdue() ? 'danger' : null)
                    ->icon(fn (Task $record): ?string => $record->isOverdue() ? 'heroicon-o-exclamation-triangle' : null)
                    ->description(fn (Task $record): ?string => $record->isOverdue() ? (string) __('workflows.tasks.overdue') : null),
                TextColumn::make('created_at')->label(__('workflows.tasks.created_at'))->since()->sortable(),
            ])
            ->recordActions([
                ReviewActions::approve($open),
                ReviewActions::reject($open),
                Action::make('open')->label(__('workflows.tasks.open_submission'))->icon('heroicon-o-eye')->color('gray')
                    ->url(fn (Task $record): string => SubmissionResource::getUrl('view', ['record' => $record->submission_uuid])),
            ]);
    }

    /** @return Builder<Task> */
    private static function query(): Builder
    {
        /** @var User $user */
        $user = auth()->user();

        return MyTasks::openTasks($user)->with(['submission.form', 'assigneeUser', 'assigneeGroup']);
    }
}
