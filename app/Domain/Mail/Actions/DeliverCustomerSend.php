<?php

declare(strict_types=1);

namespace App\Domain\Mail\Actions;

use App\Domain\Documents\Models\Rendition;
use App\Domain\Identity\Tenancy\TenantContext;
use App\Domain\Identity\Tenancy\TenantNotSetException;
use App\Domain\Mail\CustomerSend\CustomerSendTemplate;
use App\Domain\Mail\Enums\DocumentSendStatus;
use App\Domain\Mail\Models\DocumentSend;
use Illuminate\Support\Facades\DB;

/**
 * Queues the mail of a waiting send request with the given rendition (§9.2): localized default
 * template in the organization's locale, PDF attached or linked (DocumentDelivery via SendDocuments).
 * The row lock and status check make it safe to call twice (listener retry, concurrent request):
 * only the first call sends. The "document.sent" audit entry is written by DeliverMail once delivered.
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

            $template = CustomerSendTemplate::for($organization->locale, $locked->recipient_name);
            $message = $this->documents->handle($template, $locked->recipient_email, $locked->recipient_name, $locked->submission, $rendition);
            $locked->markQueued($message->id, $rendition->id);

            return $locked;
        });
    }
}
