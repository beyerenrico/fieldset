<?php

declare(strict_types=1);

use App\Domain\Dashboards\CacheScope;
use App\Domain\Dashboards\DashboardCache;
use App\Domain\Dashboards\DashboardContext;
use App\Domain\Dashboards\DashboardData;
use App\Domain\Dashboards\DateRange;
use App\Domain\Identity\Enums\SystemRole;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Tests\Feature\Dashboards\DashboardFixtures as D;

/* M5-T04 cache: per organization (+ per user where visibility differs), short TTL, keys include the filter. */

beforeEach(function (): void {
    Carbon::setTestNow(Carbon::parse('2026-10-16 10:00', 'UTC'));
    config(['dashboards.cache_ttl' => 60]);
    $this->org = makeOrganization();
    $this->owner = ownerOf($this->org);
    $this->form = D::form($this->org, $this->owner);
    D::submission($this->form, $this->owner, 'submitted', now()->subHour(), 'SR-1');
});

afterEach(fn () => Carbon::setTestNow());

function cachedKpis(User $user, ?array $filters = null): array
{
    return app(TenantContext::class)->run(test()->org, fn (): array => app(DashboardData::class)
        ->kpis(DashboardContext::current($user, DateRange::fromFilters($filters, 'Europe/Berlin'))));
}

it('serves widget results from the cache for the TTL, then recomputes', function (): void {
    expect(cachedKpis($this->owner)['submissions'])->toBe(1);

    D::submission($this->form, $this->owner, 'submitted', now()->subMinute(), 'SR-2');
    expect(cachedKpis($this->owner)['submissions'])->toBe(1);

    $this->travel(61)->seconds();
    expect(cachedKpis($this->owner)['submissions'])->toBe(2);
});

it('keys the cache by the date filter', function (): void {
    expect(cachedKpis($this->owner)['submissions'])->toBe(1);
    D::submission($this->form, $this->owner, 'submitted', now()->subMinute(), 'SR-2');

    expect(cachedKpis($this->owner, ['startDate' => '2026-10-01'])['submissions'])->toBe(2)
        ->and(cachedKpis($this->owner)['submissions'])->toBe(1);
});

it('shares organization figures between users but never between organizations', function (): void {
    $viewer = memberOf($this->org, SystemRole::Viewer);
    expect(cachedKpis($this->owner)['submissions'])->toBe(1);
    D::submission($this->form, $this->owner, 'submitted', now()->subMinute(), 'SR-2');
    expect(cachedKpis($viewer)['submissions'])->toBe(1); // same organization entry

    $other = makeOrganization(name: 'Other GmbH');
    $context = fn (User $user, int $orgId): DashboardContext => new DashboardContext($user, $orgId, DateRange::lastDays(7, 'Europe/Berlin'));
    $cache = app(DashboardCache::class);

    expect($cache->key($context($this->owner, $this->org->id), 'kpis', CacheScope::Organization))
        ->not->toBe($cache->key($context(ownerOf($other), $other->id), 'kpis', CacheScope::Organization));
});

it('caches visibility-dependent lists per user unless the user sees all submissions', function (): void {
    $reviewer = memberOf($this->org, SystemRole::Reviewer);
    $colleague = memberOf($this->org, SystemRole::Reviewer);
    $viewer = memberOf($this->org, SystemRole::Viewer);
    $range = DateRange::lastDays(7, 'Europe/Berlin');
    $key = fn (User $user): string => app(TenantContext::class)->run($this->org, fn (): string => app(DashboardCache::class)
        ->key(DashboardContext::current($user, $range), 'recent_submissions', CacheScope::Visibility));

    expect($key($reviewer))->not->toBe($key($colleague))
        ->and($key($viewer))->toBe($key($this->owner))
        ->and($key($viewer))->toContain(':all:');
});

it('recomputes when the range changes because the key holds start, end and time zone', function (): void {
    $context = new DashboardContext($this->owner, $this->org->id, DateRange::fromFilters(['startDate' => '2026-10-01', 'endDate' => '2026-10-02'], 'Europe/Berlin'));

    expect(app(DashboardCache::class)->key($context, 'kpis', CacheScope::Organization))
        ->toBe("dashboard:{$this->org->id}:org:kpis:2026-10-01_2026-10-02_Europe/Berlin");
});
