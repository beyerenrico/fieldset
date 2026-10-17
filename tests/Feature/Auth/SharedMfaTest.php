<?php

declare(strict_types=1);

use App\Domain\Identity\Auth\SharedAppAuthentication;
use App\Domain\Identity\Models\User;
use PragmaRX\Google2FA\Google2FA;

function enrollViaFilament(User $user): string
{
    $mfa = SharedAppAuthentication::make()->recoverable();
    $secret = $mfa->generateSecret();
    $mfa->saveSecret($user, $secret);
    $mfa->saveRecoveryCodes($user, ['code-one-1111', 'code-two-2222']);

    return $secret;
}

it('lets the Field App (Fortify) challenge a user enrolled in Filament with the same secret', function (): void {
    $user = User::factory()->create(['password' => 'secret-password']);
    $secret = enrollViaFilament($user);

    $this->postJson('/auth/login', ['email' => $user->email, 'password' => 'secret-password'])
        ->assertOk()->assertJson(['two_factor' => true]);

    $this->withSession(['login.id' => $user->id, 'login.remember' => false])
        ->postJson('/auth/two-factor-challenge', ['code' => (new Google2FA)->getCurrentOtp($secret)])
        ->assertNoContent();

    $this->assertAuthenticatedAs($user);
});

it('shares recovery codes: a code issued in Filament works once in Fortify', function (): void {
    $user = User::factory()->create();
    enrollViaFilament($user);

    $this->withSession(['login.id' => $user->id, 'login.remember' => false])
        ->postJson('/auth/two-factor-challenge', ['recovery_code' => 'code-one-1111'])
        ->assertNoContent();

    expect($user->fresh()->recoveryCodes())->toBe(['code-two-2222']);
});

it('lets Filament verify a secret enrolled through Fortify', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user)->withSession(['auth.password_confirmed_at' => time()]);

    $this->postJson('/auth/user/two-factor-authentication')->assertOk();
    $secret = decrypt($user->fresh()->two_factor_secret);
    $this->postJson('/auth/user/confirmed-two-factor-authentication', ['code' => (new Google2FA)->getCurrentOtp($secret)])
        ->assertOk();

    $user->refresh();
    $mfa = SharedAppAuthentication::make()->recoverable();

    expect($mfa->isEnabled($user))->toBeTrue()
        ->and($mfa->getSecret($user))->toBe($secret)
        ->and($mfa->verifyRecoveryCode($user->recoveryCodes()[0], $user))->toBeTrue()
        ->and($user->fresh()->recoveryCodes())->toHaveCount(7);
});

it('treats an unconfirmed Fortify secret as not enrolled in Filament', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user)->withSession(['auth.password_confirmed_at' => time()]);
    $this->postJson('/auth/user/two-factor-authentication')->assertOk();

    expect(SharedAppAuthentication::make()->isEnabled($user->fresh()))->toBeFalse();
});

it('clears all MFA data when Filament disables it', function (): void {
    $user = User::factory()->create();
    enrollViaFilament($user);

    SharedAppAuthentication::make()->saveSecret($user, null);

    expect($user->fresh())->two_factor_secret->toBeNull()
        ->two_factor_recovery_codes->toBeNull()
        ->two_factor_confirmed_at->toBeNull();
});

it('renders the set-up QR code as a single-encoded SVG data URI', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user);
    $mfa = SharedAppAuthentication::make();

    $uri = $mfa->generateQrCodeDataUri($mfa->generateSecret());

    expect($uri)->toStartWith('data:image/svg+xml;base64,')
        ->and(base64_decode(substr($uri, strlen('data:image/svg+xml;base64,')), true))->toContain('<svg');
});
