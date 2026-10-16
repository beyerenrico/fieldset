<?php

declare(strict_types=1);

namespace App\Domain\Mail\Listeners;

use App\Domain\Documents\Events\RenditionCreated;
use App\Domain\Documents\Models\Rendition;
use App\Domain\Identity\Tenancy\TenantContext;
use App\Domain\Mail\Actions\DeliverCustomerSend;
use App\Domain\Mail\Enums\DocumentSendStatus;
use App\Domain\Mail\Models\DocumentSend;
use Illuminate\Contracts\Queue\ShouldQueue;
use Throwable;

/**
 * Mails the "send to customer" requests that waited for the PDF once a rendition exists (§9.2 "goes out
 * after sync, once the number and PDF exist"). Queued with its own retries, so a failure never fails the
 * render job; the tenant is restored explicitly from the event. After the last attempt the waiting
 * requests are marked failed and can be sent again from the inbox.
 */
final class SendWaitingDocuments implements ShouldQueue
{
    public bool $afterCommit = true;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [10, 60];

    public function __construct(private readonly TenantContext $tenant, private readonly DeliverCustomerSend $deliver) {}

    public function handle(RenditionCreated $event): void
    {
        $this->tenant->run($event->organizationId, function () use ($event): void {
            $rendition = Rendition::query()->find($event->renditionId);
            if (! $rendition instanceof Rendition) {
                return;
            }

            foreach ($this->waiting($event->submissionUuid) as $send) {
                $this->deliver->handle($send, $rendition);
            }
        });
    }

    public function failed(RenditionCreated $event, ?Throwable $exception): void
    {
        $this->tenant->run($event->organizationId, function () use ($event, $exception): void {
            foreach ($this->waiting($event->submissionUuid) as $send) {
                $send->markFailed($exception?->getMessage() ?: 'Sending failed.');
            }
        });
    }

    /** @return iterable<DocumentSend> */
    private function waiting(string $submissionUuid): iterable
    {
        return DocumentSend::query()->where('submission_uuid', $submissionUuid)
            ->where('status', DocumentSendStatus::WaitingForDocument)->orderBy('id')->get();
    }
}
