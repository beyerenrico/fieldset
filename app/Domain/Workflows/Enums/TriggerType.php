<?php

declare(strict_types=1);

namespace App\Domain\Workflows\Enums;

use App\Domain\Submissions\Enums\SubmissionStatus;

/** Workflow triggers (BUILD_PROMPT §8.3). Submission triggers all derive from one status change. */
enum TriggerType: string
{
    case SubmissionSubmitted = 'submission.submitted';
    case SubmissionStatusChanged = 'submission.status_changed';
    case SubmissionApproved = 'submission.approved';
    case SubmissionRejected = 'submission.rejected';
    case FormPublished = 'form.published';
    case Schedule = 'schedule';
    case Manual = 'manual';

    /** Triggers whose runs carry a submission. */
    public function hasSubmission(): bool
    {
        return ! in_array($this, [self::FormPublished, self::Schedule], true);
    }

    /** Triggers that may be narrowed to some forms (`trigger_config.form_ids`). */
    public function filtersForms(): bool
    {
        return $this !== self::Schedule;
    }

    /** Whether a transition to `$to` fires this trigger (status_changed checks its `to_status` separately). */
    public function firesOnTransitionTo(SubmissionStatus $to): bool
    {
        return match ($this) {
            self::SubmissionSubmitted => $to === SubmissionStatus::Submitted,
            self::SubmissionApproved => $to === SubmissionStatus::Approved,
            self::SubmissionRejected => $to === SubmissionStatus::ChangesRequested,
            self::SubmissionStatusChanged => true,
            default => false,
        };
    }

    public function label(): string
    {
        return __('workflows.triggers.'.str_replace('.', '_', $this->value));
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        $options = [];
        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }
}
