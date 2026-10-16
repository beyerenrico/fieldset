<?php

declare(strict_types=1);

namespace App\Domain\Dashboards;

use Closure;
use Illuminate\Contracts\Cache\Repository;

/**
 * Short-lived widget cache (§8.1 "cache results per organization with a short TTL"). Keys hold the
 * organization, the viewer segment (CacheScope), the widget and the date range, so a filter change or
 * another organization never reads a foreign result. Values are plain arrays.
 */
final readonly class DashboardCache
{
    public function __construct(private Repository $cache) {}

    /**
     * @template T
     *
     * @param  Closure(): T  $compute
     * @return T
     */
    public function remember(DashboardContext $context, string $widget, CacheScope $scope, Closure $compute): mixed
    {
        return $this->cache->remember(
            $this->key($context, $widget, $scope),
            max(1, (int) config('dashboards.cache_ttl', 60)),
            $compute,
        );
    }

    public function key(DashboardContext $context, string $widget, CacheScope $scope): string
    {
        return implode(':', [
            'dashboard', $context->organizationId, $context->cacheSegment($scope), $widget, $context->range->cacheKey(),
        ]);
    }
}
