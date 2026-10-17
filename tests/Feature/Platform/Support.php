<?php

declare(strict_types=1);

use App\Domain\Identity\Auth\SharedAppAuthentication;
use App\Domain\Identity\Enums\SystemRole;
use App\Domain\Identity\Models\Membership;
use App\Domain\Identity\Models\Organization;
use App\Domain\Identity\Models\User;
use App\Domain\Platform\Actions\GrantPlatformAdmin;
use App\Domain\Platform\Auth\PlatformSession;
use App\Domain\Platform\Impersonation\StartImpersonation;
use Filament\Facades\Filament;

/*
 * Helpers for the /platform tests (ADR 0041), required by each test file in this directory.
 */

/** An active user with a platform_admins grant and confirmed TOTP (MFA is required on /platform). */
function platformOperator(string $name = 'Olga Operator'): User
{
    return platformOperatorGrant(User::factory()->create(['name' => $name]));
}

/** Grants an existing user the platform role and confirms TOTP for them. */
function platformOperatorGrant(User $user): User
{
    app(GrantPlatformAdmin::class)->handle($user);
    $mfa = SharedAppAuthentication::make()->recoverable();
    $mfa->saveSecret($user, $mfa->generateSecret());

    return $user->fresh() ?? $user;
}

/** Signs in like /platform/login does: web guard + the platform session mark. */
function actingAsPlatformOperator(User $user): void
{
    test()->actingAs($user);
    app(PlatformSession::class)->mark($user);
    Filament::setCurrentPanel('platform');
}

const IMPERSONATION_REASON = 'Ticket 4711: the workflow does not start';

function platformMembershipOf(User $user, Organization $organization): Membership
{
    return Membership::query()->where('user_id', $user->id)->where('organization_id', $organization->id)->sole();
}

/** @return array{0: User, 1: User, 2: Organization} operator, target, organization */
function platformImpersonationScenario(): array
{
    $org = makeOrganization(name: 'Acme GmbH');
    $target = memberOf($org, SystemRole::Admin);
    $operator = platformOperator();
    actingAsPlatformOperator($operator);

    return [$operator, $target, $org];
}

function platformStartImpersonation(User $operator, User $target, Organization $org): void
{
    app(StartImpersonation::class)->handle($operator, $org, $target, IMPERSONATION_REASON);
}
