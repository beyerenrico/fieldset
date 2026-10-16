<?php

declare(strict_types=1);

namespace App\Domain\Workflows\Tasks;

use App\Domain\Identity\Models\User;
use App\Domain\Submissions\Models\Submission;
use App\Domain\Workflows\Enums\TaskStatus;
use App\Domain\Workflows\Models\Task;

/** Review fields of the API `submission` object: the latest decision and the viewer's open task. */
final class ReviewState
{
    /** @return array{outcome: string, comment: string|null, by: array{id: int, name: string}|null, at: string|null}|null */
    public static function review(Submission $submission): ?array
    {
        $task = Task::query()->where('submission_uuid', $submission->uuid)->where('status', TaskStatus::Completed->value)
            ->whereNotNull('outcome')->with('completer')->orderByDesc('completed_at')->orderByDesc('id')->first();
        if (! $task instanceof Task || $task->outcome === null) {
            return null;
        }

        return [
            'outcome' => $task->outcome->value,
            'comment' => $task->comment,
            'by' => $task->completer instanceof User ? ['id' => $task->completer->id, 'name' => $task->completer->name] : null,
            'at' => $task->completed_at?->toIso8601String(),
        ];
    }

    public static function openTaskId(Submission $submission, ?User $viewer): ?int
    {
        return $viewer instanceof User ? app(TaskAccess::class)->openTaskFor($viewer, $submission)?->id : null;
    }
}
