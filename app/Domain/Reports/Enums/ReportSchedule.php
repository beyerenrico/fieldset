<?php

declare(strict_types=1);

namespace App\Domain\Reports\Enums;

/** Scheduled report emails (v1.0, BUILD_PROMPT §8.4): Mondays / the 1st at 07:00 in the organization's time zone. */
enum ReportSchedule: string
{
    case None = 'none';
    case Weekly = 'weekly';
    case Monthly = 'monthly';

    public function label(): string
    {
        return __('reports.schedule.'.$this->value);
    }
}
