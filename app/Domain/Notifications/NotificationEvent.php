<?php

declare(strict_types=1);

namespace App\Domain\Notifications;

/**
 * Notification events (BUILD_PROMPT §8.9). Users choose channels per event in their preferences.
 * `export_ready` (M5) and `sso_misconfigured` (M7) are added with their milestones.
 */
enum NotificationEvent: string
{
    case ReviewAssigned = 'review_assigned';
    case ChangesRequested = 'changes_requested';
    case SubmissionApproved = 'submission_approved';
    case WorkflowFailed = 'workflow_failed';
    case FormAvailable = 'form_available';
    /** Free-text message of a workflow `notify` step. */
    case WorkflowNotice = 'workflow_notice';
}
