<?php

declare(strict_types=1);

namespace App\Domain\Workflows\Tasks;

use App\Domain\Identity\Models\User;
use App\Domain\Workflows\Enums\TaskStatus;
use App\Domain\Workflows\Models\Task;

/**
 * Review fields of the API `submission` object: the latest decision and the viewer's open task, loaded
 * for a whole page of submissions in one query each (SubmissionPresenter; M8-T05: per row this was three
 * queries, 150 per API page).
 *
 * @phpstan-type Review array{outcome: string, comment: string|null, by: array{id: int, name: string}|null, at: string|null}
 */
final class ReviewState
{
    /**
     * The latest decision per submission (completed review tasks with an outcome, newest completion first).
     *
     * @param  list<string>  $uuids
     * @return array<string, Review> keyed by submission uuid; submissions without a decision are missing
     */
    public static function reviewsOf(array $uuids): array
    {
        if ($uuids === []) {
            return [];
        }
        $tasks = Task::query()->whereIn('submission_uuid', $uuids)->where('status', TaskStatus::Completed->value)
            ->whereNotNull('outcome')->with('completer')->orderByDesc('completed_at')->orderByDesc('id')->get();

        $reviews = [];
        foreach ($tasks as $task) {
            if ($task->outcome !== null && ! array_key_exists($task->submission_uuid, $reviews)) {
                $reviews[$task->submission_uuid] = [
                    'outcome' => $task->outcome->value,
                    'comment' => $task->comment,
                    'by' => $task->completer instanceof User ? ['id' => $task->completer->id, 'name' => $task->completer->name] : null,
                    'at' => $task->completed_at?->toIso8601String(),
                ];
            }
        }

        return $reviews;
    }

    /**
     * The viewer's open task per submission (TaskAccess::openTaskIdsFor).
     *
     * @param  list<string>  $uuids
     * @return array<string, int> keyed by submission uuid
     */
    public static function openTaskIdsOf(array $uuids, ?User $viewer): array
    {
        return $viewer instanceof User ? app(TaskAccess::class)->openTaskIdsFor($viewer, $uuids) : [];
    }
}
