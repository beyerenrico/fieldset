<?php

declare(strict_types=1);

namespace App\Domain\Workflows\Enums;

use App\Domain\Workflows\Steps\GenerateDocumentStep;
use App\Domain\Workflows\Steps\NotifyStep;
use App\Domain\Workflows\Steps\RequestReviewStep;
use App\Domain\Workflows\Steps\SendEmailStep;
use App\Domain\Workflows\Steps\SetStatusStep;
use App\Domain\Workflows\Steps\StepHandler;
use App\Domain\Workflows\Steps\WebhookStep;

/** Workflow step types (BUILD_PROMPT §8.3). Each maps to a StepHandler. */
enum StepType: string
{
    case SendEmail = 'send_email';
    case Notify = 'notify';
    case RequestReview = 'request_review';
    case SetStatus = 'set_status';
    case GenerateDocument = 'generate_document';
    case Webhook = 'webhook';

    /** @return class-string<StepHandler> */
    public function handler(): string
    {
        return match ($this) {
            self::SendEmail => SendEmailStep::class,
            self::Notify => NotifyStep::class,
            self::RequestReview => RequestReviewStep::class,
            self::SetStatus => SetStatusStep::class,
            self::GenerateDocument => GenerateDocumentStep::class,
            self::Webhook => WebhookStep::class,
        };
    }

    /** Steps that change the submission's status themselves (ADR 0032 lifecycle rules). */
    public function managesStatus(): bool
    {
        return $this === self::RequestReview || $this === self::SetStatus;
    }

    public function label(): string
    {
        return __('workflows.steps.'.$this->value.'.label');
    }
}
