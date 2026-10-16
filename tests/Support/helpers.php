<?php

declare(strict_types=1);

use App\Domain\Identity\Actions\CreateOrganization;
use App\Domain\Identity\Enums\SystemRole;
use App\Domain\Identity\Models\Organization;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Tenancy\TenantContext;
use Filament\Facades\Filament;
use Laravel\Sanctum\Sanctum;

/** Creates an organization (roles seeded) owned by a new or given user. */
function makeOrganization(?User $owner = null, string $name = 'Acme GmbH'): Organization
{
    return app(CreateOrganization::class)->handle(['name' => $name], $owner ?? User::factory()->create());
}

function memberOf(Organization $organization, SystemRole ...$roles): User
{
    return User::factory()->memberOf($organization, ...$roles)->create();
}

function ownerOf(Organization $organization): User
{
    return $organization->members()->firstOrFail();
}

/**
 * Authenticates as the user via Sanctum and returns the tenant header for API calls.
 *
 * @return array<string, string>
 */
function apiHeaders(User $user, Organization $organization): array
{
    Sanctum::actingAs($user);

    return ['X-Organization-Id' => (string) $organization->id, 'Accept' => 'application/json'];
}

/** Authenticates in the admin panel with the organization as Filament tenant and TenantContext. */
function actingInAdmin(User $user, Organization $organization): void
{
    test()->actingAs($user);
    Filament::setCurrentPanel('admin');
    Filament::setTenant($organization);
    app(TenantContext::class)->set($organization);
}
