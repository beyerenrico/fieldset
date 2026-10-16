<?php

declare(strict_types=1);

namespace App\Domain\Reports\Enums;

/** Who sees a saved report: its creator only, or every member with reports.view. */
enum ReportVisibility: string
{
    case Private = 'private';
    case Organization = 'organization';

    public function label(): string
    {
        return __('reports.visibility.'.$this->value);
    }
}
