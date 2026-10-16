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
 * `group_ids`, `roles`) on the selected channels (`channels`: database, mail; at least one — SaveWorkflow
 * requires it; an empty selection fails, a definition saved before the setting existed uses both). User preferences apply on top (Notifier). The
 * output's `notified_at` marks the notice as queued, so a retried step never sends it twice.
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
        $all = [Notifier::CHANNEL_DATABASE, Notifier::CHANNEL_MAIL];
        // Older definitions without the setting used both channels; an empty selection is not "all".
        $channels = array_key_exists('channels', $context->config) ? array_values(array_intersect($context->strings('channels'), $all)) : $all;
        if ($channels === []) {
            throw new StepFailedException(__('workflows.validation.channels_required'));
        }

        if (! isset($context->step->output['notified_at'])) {
            $this->notifications->notice($context->run, $context->submission, (string) $context->string('message'), $users, $channels);
            // Send marker right after queuing: a re-claim of this step (worker died before the result was
            // recorded) completes without notifying twice.
            $context->step->mergeOutput(['notified_at' => now()->toIso8601String()]);
            $context->step->save();
        }

        return StepResult::completed(
            __('workflows.log.notified', ['count' => $users->count()]),
            ['user_ids' => $users->modelKeys(), 'channels' => $channels],
        );
    }
}
