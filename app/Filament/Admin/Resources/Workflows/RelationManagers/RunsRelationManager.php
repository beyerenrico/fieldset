<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Workflows\RelationManagers;

use App\Domain\Identity\Models\User;
use App\Domain\Workflows\Engine\WorkflowRunner;
use App\Domain\Workflows\Enums\RunStatus;
use App\Domain\Workflows\Enums\TriggerType;
use App\Domain\Workflows\Models\Workflow;
use App\Domain\Workflows\Models\WorkflowRun;
use App\Filament\Admin\Resources\Submissions\SubmissionResource;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Contracts\View\Factory;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/** Runs of a workflow (§8.3): status, error, a per-step timeline with logs, and retry of failed runs. */
class RunsRelationManager extends RelationManager
{
    protected static string $relationship = 'runs';

    /** Re-authorize on every Livewire request: permissions may change while a tab stays open. */
    public function hydrate(): void
    {
        abort_unless(auth()->user()?->can('view', $this->ownerWorkflow()) ?? false, 403);
    }

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return auth()->user()?->can('view', $ownerRecord) ?? false;
    }

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('workflows.runs.title');
    }

    public function table(Table $table): Table
    {
        return $table
            ->modelLabel(__('workflows.runs.id'))->pluralModelLabel(__('workflows.runs.title'))
            ->defaultSort('id', 'desc')
            ->modifyQueryUsing(fn ($query) => $query->with('submission'))
            ->poll('15s')
            ->columns([
                TextColumn::make('id')->label(__('workflows.runs.id'))->prefix('#'),
                TextColumn::make('trigger')->label(__('workflows.runs.trigger'))->badge()
                    ->formatStateUsing(fn (string $state): string => TriggerType::tryFrom($state)?->label() ?? $state),
                TextColumn::make('submission.number')->label(__('workflows.runs.submission'))->placeholder('—')
                    ->url(fn (WorkflowRun $record): ?string => $record->submission_uuid === null ? null : SubmissionResource::getUrl('view', ['record' => $record->submission_uuid])),
                TextColumn::make('status')->label(__('workflows.runs.status'))->badge()
                    ->formatStateUsing(fn (WorkflowRun $record): string => $record->status->label())
                    ->color(fn (WorkflowRun $record): string => $record->status->color()),
                TextColumn::make('current_step')->label(__('workflows.runs.step'))
                    ->formatStateUsing(fn (WorkflowRun $record): string => ($record->current_step + 1).' / '.count($record->steps())),
                TextColumn::make('started_at')->label(__('workflows.runs.started_at'))->dateTime()->timezone(SubmissionResource::displayTimezone())->placeholder('—'),
                TextColumn::make('finished_at')->label(__('workflows.runs.finished_at'))->dateTime()->timezone(SubmissionResource::displayTimezone())->placeholder('—'),
                TextColumn::make('error')->label(__('workflows.runs.error'))->limit(80)->placeholder('—')->color('danger'),
            ])
            ->recordActions([$this->timelineAction(), $this->retryAction()]);
    }

    private function timelineAction(): Action
    {
        return Action::make('timeline')->label(__('workflows.runs.timeline'))->icon('heroicon-o-queue-list')->color('gray')
            ->authorize(fn (WorkflowRun $record): bool => auth()->user()?->can('view', $record) ?? false)
            ->modalHeading(fn (WorkflowRun $record): string => __('workflows.runs.timeline').' #'.$record->id)
            ->modalSubmitAction(false)
            ->modalContent(fn (WorkflowRun $record): Factory|\Illuminate\Contracts\View\View => view('workflows.admin.run-timeline', ['run' => $record->load('stepRuns')]));
    }

    private function retryAction(): Action
    {
        return Action::make('retry')->label(__('workflows.runs.retry'))->icon('heroicon-o-arrow-path')->color('warning')
            ->visible(fn (WorkflowRun $record): bool => $record->status === RunStatus::Failed)
            ->authorize(fn (WorkflowRun $record): bool => auth()->user()?->can('retry', $record) ?? false)
            ->requiresConfirmation()->modalDescription(__('workflows.runs.retry_help'))
            ->action(function (WorkflowRun $record): void {
                /** @var User $actor */
                $actor = auth()->user();
                try {
                    app(WorkflowRunner::class)->retry($record, $actor);
                } catch (InvalidArgumentException $e) {
                    Notification::make()->danger()->title($e->getMessage())->send();

                    return;
                }
                Notification::make()->success()->title(__('workflows.runs.retried'))->send();
            });
    }

    private function ownerWorkflow(): Workflow
    {
        /** @var Workflow $workflow */
        $workflow = $this->getOwnerRecord();

        return $workflow;
    }
}
