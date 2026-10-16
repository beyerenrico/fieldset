<?php

declare(strict_types=1);

namespace App\Domain\Workflows\Steps;

use App\Domain\Mail\Models\MailMessage;
use App\Domain\Workflows\Engine\StepFailedException;

/**
 * Caps on workflow emails (M4 review S3, like the customer-send caps): `send_email` recipients may come
 * from submitted field values, so a workflow must not turn the organization's sender into a relay. At most
 * `workflows.email.per_submission_per_day` mails per submission in 24 hours and `per_organization_per_hour`
 * per organization in one hour, counted from the mail log (mails linked to a step run). Over a cap the step
 * fails permanently (admins are notified); a manual retry later sends only the recipients still missing.
 * Runs inside the tenant.
 */
final class WorkflowMailLimits
{
    /** @throws StepFailedException */
    public static function assertWithin(?string $submissionUuid): void
    {
        if ($submissionUuid !== null) {
            $perSubmission = max(1, (int) config('workflows.email.per_submission_per_day', 25));
            $recent = MailMessage::query()->whereNotNull('workflow_step_run_id')->where('submission_uuid', $submissionUuid)
                ->where('created_at', '>=', now()->subDay())->count();
            if ($recent >= $perSubmission) {
                throw new StepFailedException(__('workflows.errors.mail_limit_submission', ['max' => $perSubmission]));
            }
        }

        $perOrganization = max(1, (int) config('workflows.email.per_organization_per_hour', 500));
        if (MailMessage::query()->whereNotNull('workflow_step_run_id')->where('created_at', '>=', now()->subHour())->count() >= $perOrganization) {
            throw new StepFailedException(__('workflows.errors.mail_limit_organization', ['max' => $perOrganization]));
        }
    }
}
