<?php

declare(strict_types=1);

namespace App\Domain\Platform\Health;

use App\Domain\Platform\Health\Checks\DiskCheck;
use App\Domain\Platform\Health\Checks\GotenbergCheck;
use App\Domain\Platform\Health\Checks\MailCheck;
use App\Domain\Platform\Health\Checks\QueueCheck;
use App\Domain\Platform\Health\Checks\RuntimeCheck;
use App\Domain\Platform\Health\Checks\SchedulerCheck;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Runs the operator health checks (§8.12). A check never breaks the page: an exception (Redis down,
 * Gotenberg timeout) becomes a failed result naming only the exception class; details go to the log.
 */
final readonly class PlatformHealth
{
    /** @var list<class-string<HealthCheck>> */
    public const array CHECKS = [QueueCheck::class, GotenbergCheck::class, MailCheck::class, SchedulerCheck::class, DiskCheck::class, RuntimeCheck::class];

    public function __construct(private Container $container) {}

    /** @return list<HealthResult> */
    public function run(): array
    {
        return array_map($this->safely(...), self::CHECKS);
    }

    /** @param  class-string<HealthCheck>  $class */
    private function safely(string $class): HealthResult
    {
        $key = strtolower(str_replace('Check', '', class_basename($class)));

        try {
            /** @var HealthCheck $check */
            $check = $this->container->make($class);

            return $check->run();
        } catch (Throwable $e) {
            Log::warning('Platform health check failed.', ['check' => $key, 'exception' => $e::class, 'message' => $e->getMessage()]);

            return HealthResult::fail($key, (string) __('platform.health.unavailable', ['error' => class_basename($e)]));
        }
    }
}
