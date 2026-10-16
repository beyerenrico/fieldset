<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Submissions\Actions;

use App\Domain\Identity\Models\User;
use App\Domain\Submissions\Enums\SubmissionStatus;
use App\Domain\Submissions\Models\Submission;
use App\Domain\Workflows\Models\Workflow;
use App\Domain\Workflows\Triggers\StartManualWorkflow;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;

/** "Run workflow" on a submission (§8.3 `manual` trigger): active manual workflows of the submission's form. */
final class RunWorkflowAction
{
    public static function make(): Action
    {
        return Action::make('runWorkflow')->label(__('workflows.manual.action'))->icon('heroicon-o-play')->color('gray')
            ->visible(fn (Submission $record): bool => $record->status !== SubmissionStatus::Draft
                && app(StartManualWorkflow::class)->available($record)->isNotEmpty())
            ->authorize(function (Submission $record): bool {
                $user = auth()->user();

                return $user instanceof User && app(StartManualWorkflow::class)->allows($user, $record);
            })
            ->schema(fn (Submission $record): array => [
                Select::make('workflow_id')->label(__('workflows.manual.workflow'))->required()
                    ->options(app(StartManualWorkflow::class)->available($record)->pluck('name', 'id')->all()),
            ])
            ->action(function (Submission $record, array $data): void {
                /** @var User $actor */
                $actor = auth()->user();
                $workflow = app(StartManualWorkflow::class)->available($record)->first(fn (Workflow $w): bool => $w->id === (int) ($data['workflow_id'] ?? 0));
                if (! $workflow instanceof Workflow) {
                    return;
                }
                $run = app(StartManualWorkflow::class)->handle($workflow, $record, $actor);
                $run === null
                    ? Notification::make()->warning()->title(__('workflows.manual.not_matched', ['name' => $workflow->name]))->send()
                    : Notification::make()->success()->title(__('workflows.manual.started', ['name' => $workflow->name]))->send();
            });
    }
}
