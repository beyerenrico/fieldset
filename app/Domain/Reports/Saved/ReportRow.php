<?php

declare(strict_types=1);

namespace App\Domain\Reports\Saved;

use App\Domain\Reports\Enums\ReportMetric;

/**
 * One group of a saved report result. `sum` and `avg` are exact decimal strings from Postgres numeric
 * arithmetic (trailing zeros trimmed), null when the group has no value for the metric key.
 */
final readonly class ReportRow
{
    public function __construct(
        public ?string $key,
        public string $label,
        public int $submissions,
        public ?string $sum = null,
        public ?string $avg = null,
        public int $values = 0,
    ) {}

    /** The metric value as a decimal string ("12", "0.3", "1234.5"); null for an average without values. */
    public function value(ReportMetric $metric): ?string
    {
        return match ($metric) {
            ReportMetric::Count => (string) $this->submissions,
            ReportMetric::Sum => $this->sum ?? '0',
            ReportMetric::Avg => $this->avg,
        };
    }
}
