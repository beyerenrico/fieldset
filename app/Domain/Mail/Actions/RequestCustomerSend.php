<?php

declare(strict_types=1);

namespace App\Domain\Mail\Actions;

use App\Domain\Documents\Models\Rendition;
use App\Domain\Documents\Renditions\RenderSubmission;
use App\Domain\Identity\Models\User;
use App\Domain\Mail\CustomerSend\CustomerRecipient;
use App\Domain\Mail\CustomerSend\CustomerSendLimits;
use App\Domain\Mail\Enums\DocumentSendStatus;
use App\Domain\Mail\Models\DocumentSend;
use App\Domain\Submissions\Models\Submission;
use Illuminate\Support\Facades\DB;

/**
 * "Send to customer" (§9.2, M3-T08), shared by the API and the admin inbox. Records the request; with a
 * rendition the mail is queued at once, otherwise the request waits and SendWaitingDocuments mails it
 * when the first rendition is created. Holding the per-submission rendition lock while checking means
 * a rendition committed concurrently is either seen here or announces itself after this commit.
 * Authorization (CustomerSendGate + visibility) is the caller's job; the caps (CustomerSendLimits) are
 * checked here, under the lock, for every caller.
 */
final readonly class RequestCustomerSend
{
    public function __construct(private DeliverCustomerSend $deliver) {}

    public function handle(User $actor, Submission $submission, CustomerRecipient $recipient): DocumentSend
    {
        return DB::transaction(function () use ($actor, $submission, $recipient): DocumentSend {
            DB::select('select pg_advisory_xact_lock(?)', [RenderSubmission::lockId($submission->uuid)]);
            CustomerSendLimits::assertWithin($submission);

            $send = DocumentSend::query()->create([
                'submission_uuid' => $submission->uuid,
                'recipient_email' => $recipient->email,
                'recipient_name' => $recipient->name,
                'status' => DocumentSendStatus::WaitingForDocument,
                'requested_by' => $actor->id,
            ]);
            $rendition = Rendition::query()->where('submission_uuid', $submission->uuid)->orderByDesc('id')->first();

            return $rendition instanceof Rendition ? $this->deliver->handle($send, $rendition) : $send;
        });
    }
}
