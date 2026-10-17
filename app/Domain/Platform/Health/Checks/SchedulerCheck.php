<?php

declare(strict_types=1);

namespace App\Domain\Platform\Health\Checks;

use App\Domain\Platform\Health\HealthCheck;
use App\Domain\Platform\Health\HealthResult;
use App\Domain\Platform\Health\SchedulerHeartbeat;
use Carbon\CarbonImmutable;

/** The scheduler's last task run: ≤ 10 min ok, ≤ 60 min warn, older fails; never recorded warns. */
final readonly class SchedulerCheck implements HealthCheck
{
    private const int OK_MINUTES = 10;

    private const int WARN_MINUTES = 60;

    public function __construct(private SchedulerHeartbeat $heartbeat) {}

    public function key(): string
    {
        return 'scheduler';
    }

    public function run(): HealthResult
    {
        $lastRun = $this->heartbeat->lastRun();
        if (! $lastRun instanceof CarbonImmutable) {
            return HealthResult::warn($this->key(), (string) __('platform.health.scheduler.never'));
        }

        $minutes = (int) floor($lastRun->diffInMinutes(CarbonImmutable::now(), true));
        $details = [(string) __('platform.health.scheduler.last_run') => $lastRun->toIso8601String()];
        $summary = (string) __('platform.health.scheduler.ago', ['minutes' => $minutes]);

        return match (true) {
            $minutes <= self::OK_MINUTES => HealthResult::ok($this->key(), $summary, $details),
            $minutes <= self::WARN_MINUTES => HealthResult::warn($this->key(), $summary, $details),
            default => HealthResult::fail($this->key(), $summary, $details),
        };
    }
}
