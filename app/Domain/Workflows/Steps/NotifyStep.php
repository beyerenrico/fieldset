<?php

declare(strict_types=1);

namespace App\Domain\Workflows\Steps;

use App\Domain\Notifications\Notifier;
use App\Domain\Workflows\Engine\StepContext;
use App\Domain\Workflows\Engine\StepFailedException;
use App\Domain\Workflows\Engine\StepResult;
use App\Domain\Workflows\Support\WorkflowNotifications;
use App\Domain\Workflows\Support\WorkflowRecipients;

/**
 * `notify`: a free-text WorkflowNotice to users, group members and role holders (config `user_ids`,
 * `group_ids`, `roles`) on the selected channels (`channels`: database, mail; none = all). User
 * preferences apply on top (Notifier).
 */
final readonly class NotifyStep implements StepHandler
{
    public function __construct(private WorkflowRecipients $recipients, private WorkflowNotifications $notifications) {}

    public function execute(StepContext $context): StepResult
    {
        $users = $this->recipients->users($context->ints('user_ids'), $context->ints('group_ids'), $context->strings('roles'));
        if ($users->isEmpty()) {
            throw new StepFailedException(__('workflows.errors.no_recipients'));
        }
        $channels = array_values(array_intersect($context->strings('channels'), [Notifier::CHANNEL_DATABASE, Notifier::CHANNEL_MAIL]));

        $this->notifications->notice($context->run, $context->submission, (string) $context->string('message'), $users, $channels === [] ? null : $channels);

        return StepResult::completed(
            __('workflows.log.notified', ['count' => $users->count()]),
            ['user_ids' => $users->modelKeys(), 'channels' => $channels],
        );
    }
}
