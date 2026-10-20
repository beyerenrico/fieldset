<?php

declare(strict_types=1);

use App\Domain\Identity\Actions\CreateOrganization;
use App\Domain\Identity\Enums\SystemRole;
use App\Domain\Identity\Models\Organization;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Tenancy\TenantContext;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
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

/** The organization's first member, its owner: ordered, since heap order changes once rolled-back test rows are vacuumed. */
function ownerOf(Organization $organization): User
{
    return $organization->members()->orderByPivot('id')->firstOrFail();
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

/**
 * Empties the append-only audit log for a test (inside the test transaction): the trigger lets deletes through
 * only with the transaction-local prune flag, which audit_prune() sets for its own delete (ADR 0038).
 */
function clearAuditLog(): void
{
    DB::select("SELECT set_config('app.audit_prune', 'on', true)");
    DB::table('activity_log')->delete();
    DB::select("SELECT set_config('app.audit_prune', 'off', true)");
}
