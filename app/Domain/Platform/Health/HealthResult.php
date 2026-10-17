<?php

declare(strict_types=1);

namespace App\Domain\Platform\Health;

use Carbon\CarbonInterface;

/**
 * Outcome of one health check: a status, a one-line summary and labelled detail values. Points in time stay
 * Carbon values: the page shows them in the viewer's timezone and locale (Health::results()).
 */
final readonly class HealthResult
{
    /** @param  array<string, string|int|CarbonInterface>  $details  label (already translated) => value */
    public function __construct(
        public string $key,
        public HealthStatus $status,
        public string $summary,
        public array $details = [],
    ) {}

    /** @param  array<string, string|int|CarbonInterface>  $details */
    public static function ok(string $key, string $summary, array $details = []): self
    {
        return new self($key, HealthStatus::Ok, $summary, $details);
    }

    /** @param  array<string, string|int|CarbonInterface>  $details */
    public static function warn(string $key, string $summary, array $details = []): self
    {
        return new self($key, HealthStatus::Warn, $summary, $details);
    }

    /** @param  array<string, string|int|CarbonInterface>  $details */
    public static function fail(string $key, string $summary, array $details = []): self
    {
        return new self($key, HealthStatus::Fail, $summary, $details);
    }
}
