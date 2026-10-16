<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Submissions\Actions;

use App\Domain\Identity\Models\User;
use App\Domain\Workflows\Enums\TaskOutcome;
use App\Domain\Workflows\Models\Task;
use App\Domain\Workflows\Tasks\CompleteReviewTask;
use App\Http\Api\ApiException;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Model;

/**
 * "Approve" / "Request changes" (§8.3) for the inbox detail and "My tasks": the same rules as
 * POST /tasks/{id}/complete (TaskPolicy::complete, CompleteReviewTask). `$task` resolves the open task
 * of the action's record (a Task, or a Submission in the inbox).
 */
final class ReviewActions
{
    /** @param  Closure  $task  record → its open Task, or null */
    public static function approve(Closure $task): Action
    {
        return self::base('approve', $task)->label(__('workflows.tasks.approve'))->icon('heroicon-o-check-circle')->color('success')
            ->schema([Textarea::make('comment')->label(__('workflows.tasks.comment'))->rows(3)->maxLength(CompleteReviewTask::COMMENT_MAX)])
            ->action(fn (Model $record, array $data) => self::decide(self::open($task, $record), TaskOutcome::Approved, $data['comment'] ?? null));
    }

    /** @param  Closure  $task  record → its open Task, or null */
    public static function reject(Closure $task): Action
    {
        return self::base('reject', $task)->label(__('workflows.tasks.reject'))->icon('heroicon-o-arrow-uturn-left')->color('warning')
            ->schema([Textarea::make('comment')->label(__('workflows.tasks.comment_reject'))->rows(3)->required()->maxLength(CompleteReviewTask::COMMENT_MAX)])
            ->action(fn (Model $record, array $data) => self::decide(self::open($task, $record), TaskOutcome::Rejected, $data['comment'] ?? null));
    }

    private static function base(string $name, Closure $task): Action
    {
        return Action::make($name)
            ->visible(fn (Model $record): bool => self::open($task, $record) instanceof Task)
            ->authorize(function (Model $record) use ($task): bool {
                $open = self::open($task, $record);

                return $open instanceof Task && (auth()->user()?->can('complete', $open) ?? false);
            });
    }

    private static function open(Closure $task, Model $record): ?Task
    {
        $open = $task($record);

        return $open instanceof Task ? $open : null;
    }

    private static function decide(?Task $task, TaskOutcome $outcome, mixed $comment): void
    {
        /** @var User $actor */
        $actor = auth()->user();
        if (! $task instanceof Task) {
            return;
        }
        try {
            app(CompleteReviewTask::class)->handle($task, $actor, $outcome, is_string($comment) ? $comment : null);
        } catch (ApiException $e) {
            Notification::make()->danger()->title($e->getMessage())->send();

            return;
        }
        $key = $outcome === TaskOutcome::Approved ? 'workflows.tasks.approved' : 'workflows.tasks.rejected';
        Notification::make()->success()->title(__($key))->send();
    }
}
