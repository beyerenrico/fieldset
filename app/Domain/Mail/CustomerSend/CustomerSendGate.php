<?php

declare(strict_types=1);

namespace App\Domain\Mail\CustomerSend;

use App\Domain\Identity\Enums\Permission;
use App\Domain\Identity\Models\User;
use App\Domain\Mail\Enums\DocumentSendFailure;
use App\Domain\Submissions\Enums\SubmissionStatus;
use App\Domain\Submissions\Models\Submission;
use stdClass;

/**
 * Whether a user may email a submission's document to a customer (§9.2, docs/api/field-app-v1.md):
 * the pinned version allows it (`settings.sendToCustomer`), the submission is no longer a draft and not
 * void, and the user created it or holds submissions.review / organization.manage. Visibility is
 * checked by the caller (SubmissionVisibility, SubmissionPolicy::sendToCustomer); this class only
 * names the refusal so the API can answer with its error code.
 */
final class CustomerSendGate
{
    public const string NOT_SUBMITTED = 'not_submitted';

    public const string NOT_ALLOWED = 'send_not_allowed';

    /** @return self::NOT_SUBMITTED|self::NOT_ALLOWED|null null when the send is allowed */
    public static function refusal(User $user, Submission $submission): ?string
    {
        if ($submission->status === SubmissionStatus::Draft) {
            return self::NOT_SUBMITTED;
        }
        if ($submission->status === SubmissionStatus::Void || ! $user->isActive() || ! self::enabled($submission)) {
            return self::NOT_ALLOWED;
        }

        $organizationId = $submission->organization_id;
        $mayActForOthers = $user->hasPermissionIn($organizationId, Permission::SubmissionsReview)
            || $user->hasPermissionIn($organizationId, Permission::OrganizationManage);

        return $submission->created_by === $user->id || $mayActForOthers ? null : self::NOT_ALLOWED;
    }

    /**
     * Re-check when the mail is about to be queued (M3 review #5): a voided submission never gets a customer
     * mail; a request whose requester lost the right (or whose form version disallows sending) fails too.
     * A request without requester (user deleted) only needs the submission-level rules.
     */
    public static function deliveryRefusal(?User $requester, Submission $submission): ?DocumentSendFailure
    {
        if ($submission->status === SubmissionStatus::Void) {
            return DocumentSendFailure::SubmissionVoid;
        }
        $allowed = $requester instanceof User
            ? self::refusal($requester, $submission) === null
            : $submission->status !== SubmissionStatus::Draft && self::enabled($submission);

        return $allowed ? null : DocumentSendFailure::NotAllowed;
    }

    public static function allows(User $user, Submission $submission): bool
    {
        return self::refusal($user, $submission) === null;
    }

    /** `settings.sendToCustomer` of the submission's pinned form version. */
    public static function enabled(Submission $submission): bool
    {
        $settings = $submission->formVersion->definition->settings ?? null;

        return $settings instanceof stdClass && ($settings->sendToCustomer ?? false) === true;
    }
}
