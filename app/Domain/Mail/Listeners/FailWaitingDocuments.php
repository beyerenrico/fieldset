<?php

declare(strict_types=1);

namespace App\Domain\Mail\Listeners;

use App\Domain\Documents\Events\RenditionFailed;
use App\Domain\Identity\Tenancy\TenantContext;
use App\Domain\Mail\Enums\DocumentSendFailure;
use App\Domain\Mail\Models\DocumentSend;

/**
 * A permanent render failure (ADR 0031) fails the "send to customer" requests that waited for the PDF,
 * with the fixed reason `document_failed`, so they never hang. Requests made later wait again and go out
 * once a re-render succeeds. Runs synchronously after the failure committed; the tenant comes from the event.
 */
final readonly class FailWaitingDocuments
{
    public function __construct(private TenantContext $tenant) {}

    public function handle(RenditionFailed $event): void
    {
        $this->tenant->run($event->organizationId, function () use ($event): void {
            DocumentSend::failWaiting($event->submissionUuid, DocumentSendFailure::DocumentFailed, 'Rendering failed: '.$event->reason);
        });
    }
}
