<?php

declare(strict_types=1);

namespace App\Domain\Workflows\Steps;

use App\Domain\Documents\Models\Rendition;
use App\Domain\Documents\Rendering\RenderBudgetExceededException;
use App\Domain\Documents\Renditions\RenderSubmission;
use App\Domain\Mail\Actions\QueueMail;
use App\Domain\Mail\Actions\SendDocuments;
use App\Domain\Mail\Delivery\DeliveryPlan;
use App\Domain\Mail\Delivery\DocumentDelivery;
use App\Domain\Mail\Mailables\BrandedMail;
use App\Domain\Mail\Models\MailMessage;
use App\Domain\Mail\Templates\MailTemplate;
use App\Domain\Mail\Templates\MailTokens;
use App\Domain\Submissions\Models\Submission;
use App\Domain\Workflows\Engine\StepContext;
use App\Domain\Workflows\Engine\StepFailedException;
use App\Domain\Workflows\Engine\StepResult;
use Illuminate\Support\Facades\DB;

/**
 * `send_email`: one branded, logged mail per recipient (mail module, §8.8) with the step's subject and
 * Markdown body and submission tokens. `document`: `attachment` (attached up to the size limit, else a
 * link), `link` (always an expiring link) or `none`. The PDF is the newest rendition since the latest
 * submit; without one it is rendered now (retried with the step on renderer errors). An anonymized submission
 * has no PDF files any more (ADR 0039): a step with a document fails with that reason rather than mailing a
 * text that promises a missing attachment or link (`none` still sends). Mails already queued
 * by an earlier attempt are remembered in the step output and never queued twice. Each mail is linked to the
 * step run and counted against WorkflowMailLimits.
 */
final readonly class SendEmailStep implements StepHandler
{
    public const array DOCUMENT_MODES = ['none', 'attachment', 'link'];

    public function __construct(
        private EmailRecipients $recipients,
        private SendDocuments $send,
        private DocumentDelivery $delivery,
        private QueueMail $queue,
        private RenderSubmission $render,
    ) {}

    public function execute(StepContext $context): StepResult
    {
        $subject = $context->string('subject') ?? throw new StepFailedException(__('workflows.errors.subject_missing'));
        $template = new MailTemplate($subject, is_string($context->config['body'] ?? null) ? $context->config['body'] : '');
        $recipients = $this->recipients->resolve($context);
        if ($recipients === []) {
            throw new StepFailedException(__('workflows.errors.no_recipients'));
        }
        $mode = in_array($context->string('document'), self::DOCUMENT_MODES, true) ? (string) $context->string('document') : 'none';
        $rendition = $mode === 'none' ? null : $this->document($context->requireSubmission());

        /** @var array<string, int> $queued */
        $queued = (array) ($context->step->output['mail_message_ids'] ?? []);
        foreach ($recipients as $recipient) {
            $key = strtolower($recipient['email']);
            if (isset($queued[$key])) {
                continue;
            }
            WorkflowMailLimits::assertWithin($context->submission?->uuid);
            DB::transaction(function () use ($context, $template, $recipient, $rendition, $mode, $key, &$queued): void {
                $message = $this->queueOne($context, $template, $recipient, $rendition, $mode);
                $message->forceFill(['workflow_step_run_id' => $context->step->id])->save();
                $queued[$key] = $message->id;
                $context->step->mergeOutput(['mail_message_ids' => $queued]);
                $context->step->save();
            });
        }

        return StepResult::completed(
            __('workflows.log.mail_queued', ['count' => count($queued), 'recipients' => implode(', ', array_keys($queued))]),
            ['mail_message_ids' => $queued, 'rendition_id' => $rendition?->id],
        );
    }

    /** @param  array{email: string, name: string|null}  $recipient */
    private function queueOne(StepContext $context, MailTemplate $template, array $recipient, ?Rendition $rendition, string $mode): MailMessage
    {
        $submission = $context->submission;
        if (! $rendition instanceof Rendition) {
            return $this->send->handle($template, $recipient['email'], $recipient['name'], $submission);
        }
        if ($mode === 'attachment') {
            return $this->send->handle($template, $recipient['email'], $recipient['name'], $submission, $rendition);
        }
        $organization = $context->organization;
        $plan = new DeliveryPlan([], [$this->delivery->createLink($rendition)]);
        $mail = new BrandedMail($organization->id, $template, MailTokens::for($organization, $submission, $recipient['name']), $plan);

        return $this->queue->handle($mail, $recipient['email'], $recipient['name'], $submission?->uuid);
    }

    /** The newest rendition created since the latest submit, else a fresh render (deduplicated by input). */
    private function document(Submission $submission): Rendition
    {
        if ($submission->isAnonymized()) {
            throw new StepFailedException(__('documents.errors.anonymized'));
        }
        $latest = Rendition::query()->where('submission_uuid', $submission->uuid)->orderByDesc('id')->first();
        if ($latest instanceof Rendition && ($submission->submitted_at === null || $latest->created_at->greaterThanOrEqualTo($submission->submitted_at))) {
            return $latest;
        }
        try {
            return $this->render->handle($submission);
        } catch (RenderBudgetExceededException $e) {
            throw new StepFailedException($e->getMessage(), 0, $e);
        }
    }
}
