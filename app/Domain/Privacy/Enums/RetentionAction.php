<?php

declare(strict_types=1);

namespace App\Domain\Privacy\Enums;

/** What the retention job does with a submission once its form's retention period is over (ADR 0039). */
enum RetentionAction: string
{
    case Delete = 'delete';
    case Anonymize = 'anonymize';

    public function label(): string
    {
        return __('privacy.retention.actions.'.$this->value);
    }
}
