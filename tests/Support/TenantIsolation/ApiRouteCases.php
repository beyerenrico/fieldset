<?php

declare(strict_types=1);

namespace Tests\Support\TenantIsolation;

use App\Domain\Identity\Models\Organization;
use App\Domain\Identity\Models\User;

/**
 * One cross-tenant attack per tenant-scoped API route (routes with the `organization` middleware).
 * Each case receives the victim organization and the attacker (a member of another organization)
 * and returns the request to send with the attacker's credentials. The suite fails if a tenant
 * route has no case here (BUILD_PROMPT §6.2).
 */
final class ApiRouteCases
{
    /**
     * @return array<string, callable(Organization $victim, Organization $attackerOrg, User $attacker): array{0: string, 1: string, 2?: array<string, mixed>, 3?: string}>
     *                                                                                                                                                                     [method, uri, payload, header org: 'victim'|'attacker']
     */
    public static function all(): array
    {
        return [];
    }
}
