<?php

declare(strict_types=1);

namespace App\Domain\Reports\Saved;

use App\Domain\Reports\Enums\ReportMetric;

/**
 * One group of a saved report result. `sum` and `avg` are exact decimal strings from Postgres numeric
 * arithmetic (trailing zeros trimmed), null when the group has no value for the metric key. For money,
 * `currency` is the currency of the group's values (per submission version); a group whose values have
 * different currencies is `mixed` and has no sum or average (amounts in different currencies never add up).
 *
 * @phpstan-type ReportRowArray array{key: ?string, label: string, submissions: int, sum: ?string, avg: ?string, values: int, currency?: ?string, mixed?: bool}
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
        public ?string $currency = null,
        public bool $mixed = false,
    ) {}

    /** @param  ReportRowArray  $row */
    public static function fromArray(array $row): self
    {
        return new self($row['key'], $row['label'], $row['submissions'], $row['sum'], $row['avg'], $row['values'], $row['currency'] ?? null, $row['mixed'] ?? false);
    }

    /** @return ReportRowArray plain array (cacheable; cache.serializable_classes is false) */
    public function toArray(): array
    {
        return [
            'key' => $this->key, 'label' => $this->label, 'submissions' => $this->submissions, 'sum' => $this->sum, 'avg' => $this->avg,
            'values' => $this->values, 'currency' => $this->currency, 'mixed' => $this->mixed,
        ];
    }

    /** The metric value as a decimal string ("12", "0.3", "1234.5"); null for an average without values or mixed currencies. */
    public function value(ReportMetric $metric): ?string
    {
        return match ($metric) {
            ReportMetric::Count => (string) $this->submissions,
            ReportMetric::Sum => $this->mixed ? null : ($this->sum ?? '0'),
            ReportMetric::Avg => $this->mixed ? null : $this->avg,
        };
    }
}
