<?php

declare(strict_types=1);

namespace App\Domain\Mail\CustomerSend;

use App\Domain\Mail\Models\DocumentSend;
use App\Domain\Submissions\Models\Submission;

/**
 * Caps on "send to customer" (M3 review #9), so a compromised account or a buggy client cannot turn the
 * organization's sender into a spam relay: at most messaging.customer_send.per_submission_per_day requests
 * per submission in 24 hours and per_organization_per_hour per organization in one hour. Counted from
 * document_sends (durable, shared by API and admin inbox); runs inside the tenant.
 */
final class CustomerSendLimits
{
    /** @throws CustomerSendLimitExceeded */
    public static function assertWithin(Submission $submission): void
    {
        $perSubmission = max(1, (int) config('messaging.customer_send.per_submission_per_day', 10));
        $recentForSubmission = DocumentSend::query()->where('submission_uuid', $submission->uuid)
            ->where('created_at', '>=', now()->subDay())->count();
        if ($recentForSubmission >= $perSubmission) {
            throw CustomerSendLimitExceeded::perSubmission($perSubmission);
        }

        $perOrganization = max(1, (int) config('messaging.customer_send.per_organization_per_hour', 200));
        if (DocumentSend::query()->where('created_at', '>=', now()->subHour())->count() >= $perOrganization) {
            throw CustomerSendLimitExceeded::perOrganization($perOrganization);
        }
    }
}
