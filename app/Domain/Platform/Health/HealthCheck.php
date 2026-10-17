<?php

declare(strict_types=1);

namespace App\Domain\Platform\Health;

/** One operator health check (ADR 0041). May throw: PlatformHealth turns exceptions into a failed result. */
interface HealthCheck
{
    /** Stable key; the label is `platform.health.checks.{key}`. */
    public function key(): string;

    public function run(): HealthResult;
}
