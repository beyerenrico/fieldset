<?php

declare(strict_types=1);

namespace App\Domain\Dashboards;

use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Contracts\Cache\Repository;

/**
 * Short-lived widget cache (§8.1 "cache results per organization with a short TTL"). Keys hold the
 * organization, the viewer segment (CacheScope), the widget and the date range, so a filter change or
 * another organization never reads a foreign result. Entries are plain arrays {value, at}: `at` is when
 * the value was computed, shown as "as of hh:mm" on the widgets (computedAt()).
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
        $entry = $this->cache->remember(
            $this->key($context, $widget, $scope),
            max(1, (int) config('dashboards.cache_ttl', 60)),
            static fn (): array => ['value' => $compute(), 'at' => CarbonImmutable::now()->getTimestamp()],
        );

        /** @var T $value */
        $value = is_array($entry) && array_key_exists('value', $entry) && array_key_exists('at', $entry) ? $entry['value'] : $entry;

        return $value;
    }

    /** When the cached value of a widget was computed (null when nothing is cached). */
    public function computedAt(DashboardContext $context, string $widget, CacheScope $scope): ?CarbonImmutable
    {
        $entry = $this->cache->get($this->key($context, $widget, $scope));

        return is_array($entry) && is_int($entry['at'] ?? null) ? CarbonImmutable::createFromTimestamp($entry['at']) : null;
    }

    public function key(DashboardContext $context, string $widget, CacheScope $scope): string
    {
        return implode(':', [
            'dashboard', $context->organizationId, $context->cacheSegment($scope), $widget, $context->range->cacheKey(),
        ]);
    }
}
