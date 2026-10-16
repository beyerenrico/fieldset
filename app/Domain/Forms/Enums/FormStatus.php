<?php

declare(strict_types=1);

namespace App\Domain\Forms\Enums;

enum FormStatus: string
{
    case Draft = 'draft';
    case Active = 'active';
    case Archived = 'archived';

    public function label(): string
    {
        return __('forms.status.'.$this->value);
    }
}
