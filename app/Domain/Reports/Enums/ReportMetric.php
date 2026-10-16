<?php

declare(strict_types=1);

namespace App\Domain\Reports\Enums;

/** Saved report metric (BUILD_PROMPT §8.4): sums and averages run in SQL on numeric(38,10), never floats. */
enum ReportMetric: string
{
    case Count = 'count';
    case Sum = 'sum';
    case Avg = 'avg';

    public function label(): string
    {
        return __('reports.metric.'.$this->value);
    }

    public function needsField(): bool
    {
        return $this !== self::Count;
    }
}
