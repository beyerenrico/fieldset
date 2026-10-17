<?php

declare(strict_types=1);

namespace App\Domain\Notifications;

/**
 * Notification events (BUILD_PROMPT §8.9). Users choose channels per event in their preferences.
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
    /** A requested export (XLSX, CSV, PDF ZIP) finished or failed (M5, §8.4). */
    case ExportReady = 'export_ready';
    /** An organization's SSO provider or enforcement is broken (Owners and Admins; M7, ADR 0036). */
    case SsoMisconfigured = 'sso_misconfigured';
}
