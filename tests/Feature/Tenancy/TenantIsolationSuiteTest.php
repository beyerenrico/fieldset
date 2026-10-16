<?php

declare(strict_types=1);

use App\Domain\Identity\Models\Group;
use App\Domain\Identity\Models\Organization;
use App\Domain\Identity\Tenancy\BelongsToOrganization;
use App\Domain\Identity\Tenancy\TenantContext;
use App\Domain\Identity\Tenancy\TenantNotSetException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use Symfony\Component\Finder\Finder;
use Tests\Support\TenantIsolation\ApiRouteCases;

/** @return list<class-string<Model>> */
function tenantOwnedModels(): array
{
    $models = [];
    // Runs before the app boots (dataset), so resolve the path without app_path().
    foreach (Finder::create()->files()->in(dirname(__DIR__, 3).'/app/Domain')->name('*.php') as $file) {
        $class = 'App\\Domain\\'.str_replace(['/', '.php'], ['\\', ''], $file->getRelativePathname());
        if (class_exists($class) && is_subclass_of($class, Model::class)
            && in_array(BelongsToOrganization::class, class_uses_recursive($class), true)) {
            $models[] = $class;
        }
    }
    sort($models);

    return $models;
}

it('finds the tenant-owned models', function (): void {
    expect(tenantOwnedModels())->toContain(Group::class);
});

it('isolates every tenant-owned model across organizations', function (string $model): void {
    $tenant = app(TenantContext::class);
    $orgA = Organization::factory()->create();
    $orgB = Organization::factory()->create();

    $a = $tenant->run($orgA, fn () => $model::factory()->create());
    $b = $tenant->run($orgB, fn () => $model::factory()->create());

    $tenant->set($orgA);
    expect($model::query()->pluck('id')->all())->toBe([$a->getKey()])
        ->and($model::query()->find($b->getKey()))->toBeNull()
        ->and($model::query()->whereKey($b->getKey())->update(['updated_at' => now()]))->toBe(0)
        ->and($model::query()->whereKey($b->getKey())->delete())->toBe(0);

    $tenant->clear();
    expect(fn () => $model::query()->count())->toThrow(TenantNotSetException::class);
})->with(fn (): array => tenantOwnedModels());

it('has a cross-tenant case for every tenant-scoped API route', function (): void {
    $tenantRoutes = collect(Route::getRoutes()->getRoutes())
        ->filter(fn (RoutingRoute $r): bool => in_array('organization', $r->gatherMiddleware(), true))
        ->map(fn (RoutingRoute $r): string => (string) $r->getName())
        ->values()->all();

    expect(array_diff($tenantRoutes, array_keys(ApiRouteCases::all())))->toBe([]);
});

it('denies cross-tenant access on every tenant-scoped API route', function (): void {
    // Iterates instead of using a dataset so the check stays active (vacuously) before routes exist.
    foreach (ApiRouteCases::all() as $name => $case) {
        $victim = Organization::factory()->create();
        $attackerOrg = makeOrganization(name: 'Attacker '.$name);
        $attacker = ownerOf($attackerOrg);
        [$method, $uri, $payload, $headerOrg] = array_pad($case($victim, $attackerOrg, $attacker), 4, null);

        $headers = apiHeaders($attacker, ($headerOrg ?? 'attacker') === 'victim' ? $victim : $attackerOrg);
        $response = $this->withHeaders($headers)->json($method, $uri, $payload ?? []);

        expect($response->status())->toBeIn([403, 404], "Route {$name} leaked across tenants")
            ->and($response->json('success'))->toBeFalse();
    }

    expect(true)->toBeTrue();
});
