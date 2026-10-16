<?php

declare(strict_types=1);

namespace App\Domain\Workflows\Tasks;

use App\Domain\Identity\Models\User;
use App\Domain\Workflows\Models\Task;
use Illuminate\Support\Collection;

/** The task item of the API contract (`GET /tasks`, sync manifest `tasks`, docs/api/field-app-v1.md). */
final class TaskPresenter
{
    /**
     * @param  iterable<Task>  $tasks
     * @return list<array<string, mixed>>
     */
    public static function presentMany(iterable $tasks): array
    {
        $tasks = Collection::make($tasks)->values();
        $tasks->each(fn (Task $task) => $task->loadMissing(['assigneeUser', 'assigneeGroup', 'submission.form']));
        $submitterIds = $tasks->map(fn (Task $t): ?int => $t->submission->submitted_by)->filter()->unique()->values()->all();
        $submitters = User::query()->whereIn('id', $submitterIds)->get(['id', 'name'])->keyBy('id');

        return array_values($tasks->map(fn (Task $task): array => self::item($task, $submitters))->all());
    }

    /** @return array<string, mixed> */
    public static function present(Task $task): array
    {
        return self::presentMany([$task])[0];
    }

    /**
     * @param  Collection<int, User>  $submitters
     * @return array<string, mixed>
     */
    private static function item(Task $task, Collection $submitters): array
    {
        $submission = $task->submission;
        $submitter = $submission->submitted_by === null ? null : $submitters->get($submission->submitted_by);

        return [
            'id' => $task->id,
            'type' => $task->type,
            'status' => $task->status->value,
            'outcome' => $task->outcome?->value,
            'comment' => $task->comment,
            'due_at' => $task->due_at?->toIso8601String(),
            'overdue' => $task->isOverdue(),
            'created_at' => $task->created_at->toIso8601String(),
            'assignee' => self::assignee($task),
            'submission' => [
                'uuid' => $submission->uuid,
                'number' => $submission->number,
                'status' => $submission->status->value,
                'form' => ['id' => $submission->form->id, 'name' => $submission->form->name],
                'submitted_by' => $submitter instanceof User ? ['id' => $submitter->id, 'name' => $submitter->name] : null,
                'submitted_at' => $submission->submitted_at?->toIso8601String(),
            ],
        ];
    }

    /** @return array{type: string, id: int|null, name: string|null} */
    private static function assignee(Task $task): array
    {
        return $task->assignee_user_id !== null
            ? ['type' => 'user', 'id' => $task->assignee_user_id, 'name' => $task->assigneeUser?->name]
            : ['type' => 'group', 'id' => $task->assignee_group_id, 'name' => $task->assigneeGroup?->name];
    }
}
