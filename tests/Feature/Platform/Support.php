<?php

declare(strict_types=1);

use App\Domain\Identity\Auth\SharedAppAuthentication;
use App\Domain\Identity\Models\User;
use App\Domain\Platform\Actions\GrantPlatformAdmin;
use App\Domain\Platform\Auth\PlatformSession;
use Filament\Facades\Filament;

/*
 * Helpers for the /platform tests (ADR 0041), required by each test file in this directory.
 */

/** An active user with a platform_admins grant and confirmed TOTP (MFA is required on /platform). */
function platformOperator(string $name = 'Olga Operator'): User
{
    $user = User::factory()->create(['name' => $name]);
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
