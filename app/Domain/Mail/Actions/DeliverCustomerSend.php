<?php

declare(strict_types=1);

namespace App\Domain\Mail\Actions;

use App\Domain\Documents\Models\Rendition;
use App\Domain\Identity\Tenancy\TenantContext;
use App\Domain\Identity\Tenancy\TenantNotSetException;
use App\Domain\Mail\CustomerSend\CustomerSendGate;
use App\Domain\Mail\CustomerSend\CustomerSendTemplate;
use App\Domain\Mail\Delivery\DeliveryPlan;
use App\Domain\Mail\Enums\DocumentSendFailure;
use App\Domain\Mail\Enums\DocumentSendStatus;
use App\Domain\Mail\Models\DocumentSend;
use App\Domain\Mail\Templates\MailTemplate;
use App\Domain\Submissions\Models\Submission;
use Illuminate\Support\Facades\DB;

/**
 * Queues the mail of a waiting send request with the given rendition (§9.2): localized default
 * template in the organization's locale, PDF attached or linked (DocumentDelivery via SendDocuments).
 * The row lock and status check make it safe to call twice (listener retry, concurrent request):
 * only the first call sends. At delivery the request is checked again: a voided submission or a lost
 * right fails it with a fixed reason; a rendition older than the latest submit keeps it waiting for the
 * current one. The "document.sent" audit entry is written by DeliverMail once delivered.
 */
final readonly class DeliverCustomerSend
{
    public function __construct(private TenantContext $tenant, private SendDocuments $documents) {}

    public function handle(DocumentSend $send, Rendition $rendition): DocumentSend
    {
        $organization = $this->tenant->organization() ?? throw TenantNotSetException::make();

        return DB::transaction(function () use ($send, $rendition, $organization): DocumentSend {
            $locked = DocumentSend::query()->whereKey($send->id)->lockForUpdate()->first();
            if (! $locked instanceof DocumentSend || $locked->status !== DocumentSendStatus::WaitingForDocument) {
                return $locked ?? $send;
            }
            $submission = $locked->submission;
            $refusal = CustomerSendGate::deliveryRefusal($locked->requester, $submission);
            if ($refusal instanceof DocumentSendFailure) {
                $locked->markFailed($refusal);

                return $locked;
            }
            if (! self::isCurrent($rendition, $submission)) {
                return $locked;
            }

            $name = $locked->recipient_name;
            $template = static fn (DeliveryPlan $plan): MailTemplate => CustomerSendTemplate::for($organization->locale, $name, $plan->isAttachedOnly());
            $message = $this->documents->handle($template, $locked->recipient_email, $name, $submission, $rendition);
            $locked->markQueued($message->id, $rendition->id);

            return $locked;
        });
    }

    /** The rendition shows the submission as last submitted (not an earlier submit before changes were requested). */
    public static function isCurrent(Rendition $rendition, Submission $submission): bool
    {
        return $submission->submitted_at === null || $rendition->created_at->gte($submission->submitted_at);
    }
}
