<?php

declare(strict_types=1);

namespace App\Domain\Platform\Health;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\Repository as Cache;

/**
 * Last time the scheduler ran a task, recorded from ScheduledTaskStarting/Skipped events (no extra
 * scheduled command). `documents:sweep` runs every five minutes, so a healthy scheduler beats at least that often.
 */
final readonly class SchedulerHeartbeat
{
    public const string CACHE_KEY = 'platform:scheduler:last_run';

    public function __construct(private Cache $cache) {}

    public function record(): void
    {
        $this->cache->forever(self::CACHE_KEY, CarbonImmutable::now()->getTimestamp());
    }

    public function lastRun(): ?CarbonImmutable
    {
        $value = $this->cache->get(self::CACHE_KEY);

        return is_int($value) ? CarbonImmutable::createFromTimestamp($value) : null;
    }
}
