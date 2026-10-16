<?php

declare(strict_types=1);

namespace App\Domain\Workflows\Tasks;

use App\Domain\Identity\Tenancy\TenantContext;
use App\Domain\Workflows\Enums\TaskStatus;
use App\Domain\Workflows\Models\Task;
use App\Domain\Workflows\Support\WorkflowNotifications;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * Overdue review reminders (`workflows:tick`): once per task, to the assignee or the group's members.
 * The task is claimed with a conditional update, so concurrent ticks never remind twice.
 */
final readonly class SendOverdueReminders
{
    public const int BATCH = 200;

    public function __construct(private TenantContext $tenant, private WorkflowNotifications $notifications) {}

    public function handle(CarbonImmutable $now): int
    {
        $due = Task::query()->withoutTenant()->where('status', TaskStatus::Open->value)->whereNull('reminded_at')
            ->whereNotNull('due_at')->where('due_at', '<=', $now)->orderBy('due_at')->limit(self::BATCH)->get(['id', 'organization_id']);
        $sent = 0;

        foreach ($due as $task) {
            try {
                $sent += $this->tenant->run($task->organization_id, fn (): int => $this->remind($task->id, $now));
            } catch (Throwable $e) {
                report($e);
            }
        }

        return $sent;
    }

    private function remind(int $taskId, CarbonImmutable $now): int
    {
        $claimed = Task::query()->whereKey($taskId)->where('status', TaskStatus::Open->value)->whereNull('reminded_at')->update(['reminded_at' => $now]);
        $task = $claimed === 1 ? Task::query()->with('submission.form')->find($taskId) : null;
        if (! $task instanceof Task) {
            return 0;
        }
        $this->notifications->reviewOverdue($task, $task->submission);

        return 1;
    }
}
