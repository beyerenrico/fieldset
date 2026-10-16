<?php

declare(strict_types=1);

namespace App\Domain\Mail\Actions;

use App\Domain\Identity\Tenancy\TenantContext;
use App\Domain\Identity\Tenancy\TenantNotSetException;
use App\Domain\Mail\Delivery\DeliverableDocument;
use App\Domain\Mail\Delivery\DeliveryPlan;
use App\Domain\Mail\Delivery\DocumentDelivery;
use App\Domain\Mail\Mailables\BrandedMail;
use App\Domain\Mail\Models\MailMessage;
use App\Domain\Mail\Templates\MailTemplate;
use App\Domain\Mail\Templates\MailTokens;
use App\Domain\Submissions\Models\Submission;
use Closure;

/**
 * Queues a branded mail with documents to one recipient (§8.8, used by "send to customer" and
 * workflow email steps): attachments or expiring links per DocumentDelivery, submission tokens
 * available to the template, and the message logged against the submission. The template may depend on
 * the delivery plan (e.g. "enclosed" only when the PDF is attached).
 */
final readonly class SendDocuments
{
    public function __construct(
        private TenantContext $tenant,
        private DocumentDelivery $delivery,
        private QueueMail $queue,
    ) {}

    /** @param  MailTemplate|Closure(DeliveryPlan): MailTemplate  $template */
    public function handle(MailTemplate|Closure $template, string $email, ?string $name, ?Submission $submission, DeliverableDocument ...$documents): MailMessage
    {
        $organization = $this->tenant->organization() ?? throw TenantNotSetException::make();
        $plan = $this->delivery->plan(...$documents);
        $resolved = $template instanceof MailTemplate ? $template : $template($plan);
        $mail = new BrandedMail($organization->id, $resolved, MailTokens::for($organization, $submission, $name), $plan);

        return $this->queue->handle($mail, $email, $name, $submission?->uuid);
    }
}
