<?php

declare(strict_types=1);

namespace App\Domain\Workflows\Enums;

/** Decision of a review task. The API takes the verbs `approve` / `reject` (docs/api/field-app-v1.md). */
enum TaskOutcome: string
{
    case Approved = 'approved';
    case Rejected = 'rejected';

    public static function fromVerb(string $verb): self
    {
        return $verb === 'approve' ? self::Approved : self::Rejected;
    }

    public function label(): string
    {
        return __('workflows.tasks.outcome.'.$this->value);
    }
}
