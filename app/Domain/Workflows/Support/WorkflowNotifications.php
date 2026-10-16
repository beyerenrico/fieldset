<?php

declare(strict_types=1);

namespace App\Domain\Workflows\Support;

use App\Domain\Identity\Enums\Permission;
use App\Domain\Identity\Models\Organization;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Support\OrganizationPermissions;
use App\Domain\Notifications\NotificationEvent;
use App\Domain\Notifications\NotificationMessage;
use App\Domain\Notifications\Notifier;
use App\Domain\Submissions\Models\Submission;
use App\Domain\Workflows\Models\Task;
use App\Domain\Workflows\Models\WorkflowRun;
use App\Domain\Workflows\Tasks\TaskAccess;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Workflow notifications through the shared Notifier (§8.9): review assigned and overdue reminders (the
 * assignee or group members who can act on the task, TaskAccess; linked to the admin inbox for users with
 * admin access, else to the Field App), changes requested and approved (submitter), workflow failed
 * (Owners/Admins) and the free-text `notify` step. Texts are translation keys in lang/{en,de}/workflows.php,
 * rendered per recipient. A notification problem never fails the workflow: errors are reported.
 */
final readonly class WorkflowNotifications
{
    public function __construct(
        private Notifier $notifier,
        private WorkflowRecipients $recipients,
        private TaskAccess $access,
        private OrganizationPermissions $permissions,
    ) {}

    public function reviewAssigned(Task $task, Submission $submission): void
    {
        $this->review('review_assigned', $task, $submission);
    }

    public function reviewOverdue(Task $task, Submission $submission): void
    {
        $this->review('review_overdue', $task, $submission);
    }

    public function changesRequested(Submission $submission, string $comment): void
    {
        $this->send(NotificationEvent::ChangesRequested, 'changes_requested', $submission, $this->recipients->submitter($submission), ['comment' => $comment]);
    }

    public function approved(Submission $submission): void
    {
        $this->send(NotificationEvent::SubmissionApproved, 'submission_approved', $submission, $this->recipients->submitter($submission));
    }

    public function workflowFailed(WorkflowRun $run): void
    {
        $message = new NotificationMessage(
            NotificationEvent::WorkflowFailed, 'workflows.notifications.workflow_failed.title', 'workflows.notifications.workflow_failed.body',
            ['workflow' => $run->workflowName(), 'error' => (string) $run->error], $this->runUrl($run), $run->organization_id, $run->submission_uuid,
        );
        $this->deliver($message, $this->recipients->administrators());
    }

    /**
     * The `notify` step's message. Errors propagate, so the step is retried.
     *
     * @param  iterable<User>  $users
     * @param  list<string>|null  $channels
     */
    public function notice(WorkflowRun $run, ?Submission $submission, string $text, iterable $users, ?array $channels): void
    {
        $message = new NotificationMessage(
            NotificationEvent::WorkflowNotice, 'workflows.notifications.notice.title', 'workflows.notifications.notice.body',
            ['workflow' => $run->workflowName(), 'number' => (string) $submission?->number, 'message' => $text],
            $submission instanceof Submission ? self::submissionUrl($submission) : null, $run->organization_id, $submission?->uuid,
        );
        $this->notifier->send($message, $users, $channels);
    }

    /** Admin inbox detail of a submission (absolute), or null when the route cannot be built. */
    public static function adminSubmissionUrl(Submission $submission): ?string
    {
        try {
            $slug = Organization::query()->whereKey($submission->organization_id)->value('slug');

            return is_string($slug) ? route('filament.admin.resources.submissions.view', ['tenant' => $slug, 'record' => $submission->uuid]) : null;
        } catch (Throwable) {
            return null;
        }
    }

    /** Field App path of a submission (docs/api/field-app-v1.md notifications `url`). */
    public static function submissionUrl(Submission $submission): string
    {
        return '/app/submissions/'.$submission->uuid;
    }

    /** A review notification to the assignees who can act on the task, split by the link they can open. */
    private function review(string $key, Task $task, Submission $submission): void
    {
        try {
            $users = $this->recipients->assignees($task)->filter(fn (User $user): bool => $this->access->canComplete($user, $task));
            $isAdmin = fn (User $user): bool => $this->permissions->has($user, $submission->organization_id, Permission::AdminAccess);
            $admins = $users->filter($isAdmin)->values();
            $others = $users->reject($isAdmin)->values();
        } catch (Throwable $e) {
            report($e);

            return;
        }
        $this->send(NotificationEvent::ReviewAssigned, $key, $submission, $others);
        $this->send(NotificationEvent::ReviewAssigned, $key, $submission, $admins, url: self::adminSubmissionUrl($submission));
    }

    /**
     * @param  Collection<int, User>  $users
     * @param  array<string, string>  $params
     */
    private function send(NotificationEvent $event, string $key, Submission $submission, Collection $users, array $params = [], ?string $url = null): void
    {
        if ($users->isEmpty()) {
            return;
        }
        $submission->loadMissing('form');
        $message = new NotificationMessage(
            $event, "workflows.notifications.{$key}.title", "workflows.notifications.{$key}.body",
            ['number' => (string) $submission->number, 'form' => $submission->form->name, ...$params],
            $url ?? self::submissionUrl($submission), $submission->organization_id, $submission->uuid,
        );
        $this->deliver($message, $users);
    }

    /** @param  iterable<User>  $users */
    private function deliver(NotificationMessage $message, iterable $users): void
    {
        try {
            $this->notifier->send($message, $users);
        } catch (Throwable $e) {
            report($e);
        }
    }

    private function runUrl(WorkflowRun $run): ?string
    {
        try {
            $slug = Organization::query()->whereKey($run->organization_id)->value('slug');

            return is_string($slug) ? route('filament.admin.resources.workflows.edit', ['tenant' => $slug, 'record' => $run->workflow_id]) : null;
        } catch (Throwable) {
            return null;
        }
    }
}
