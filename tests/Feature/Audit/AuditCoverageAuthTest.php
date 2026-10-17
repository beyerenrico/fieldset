<?php

declare(strict_types=1);

use App\Domain\Identity\Auth\SharedAppAuthentication;
use App\Domain\Identity\Models\User;
use App\Filament\Admin\Pages\Auth\Login;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;
use PragmaRX\Google2FA\Google2FA;
use Tests\Support\AuditTrail;

/*
 * §8.11 AC, part 1 (auth events): every action writes exactly the listed entries, and secrets never reach the
 * audit log. Parts 2 and 3: AuditCoverageConfigTest, AuditCoverageSubmissionTest.
 */

beforeEach(function (): void {
    AuditTrail::reset();
    $this->user = User::factory()->create(['email' => 'max@acme.example', 'password' => 'Secret-Passw0rd!']);
});

afterEach(function (): void {
    expect(AuditTrail::seen())->not->toContain('Secret-Passw0rd!')->not->toContain('New-Passw0rd!')
        ->not->toContain('JBSWY3DPEHPK3PXP')->not->toContain('code-one-1111');
});

it('writes one entry per password sign-in, failed sign-in and sign-out', function (): void {
    expect(AuditTrail::of(fn () => $this->postJson('/auth/login', ['email' => 'max@acme.example', 'password' => 'wrong'])->assertUnprocessable()))
        ->toBe(['auth.failed'])
        ->and(AuditTrail::of(fn () => $this->postJson('/auth/login', ['email' => 'max@acme.example', 'password' => 'Secret-Passw0rd!'])->assertOk()))
        ->toBe(['auth.login'])
        ->and(AuditTrail::of(fn () => $this->postJson('/auth/logout')->assertNoContent()))
        ->toBe(['auth.logout']);
});

it('writes one lockout entry per throttled sign-in attempt (Field App and admin)', function (): void {
    foreach (range(1, 5) as $ignored) {
        $this->postJson('/auth/login', ['email' => 'max@acme.example', 'password' => 'wrong'])->assertUnprocessable();
    }
    expect(AuditTrail::of(fn () => $this->postJson('/auth/login', ['email' => 'max@acme.example', 'password' => 'wrong'])->assertStatus(429)))
        ->toBe(['auth.lockout']);

    $page = Livewire::test(Login::class)->call('usePassword');
    foreach (range(1, 5) as $ignored) {
        $page->fillForm(['email' => 'max@acme.example', 'password' => 'wrong'])->call('authenticate');
    }
    expect(AuditTrail::of(fn () => $page->fillForm(['email' => 'max@acme.example', 'password' => 'wrong'])->call('authenticate')))
        ->toBe(['auth.lockout']);
});

it('writes one entry for MFA enrolment, removal, a wrong code and a used recovery code', function (): void {
    $mfa = SharedAppAuthentication::make()->recoverable();

    expect(AuditTrail::of(fn () => $mfa->saveSecret($this->user, 'JBSWY3DPEHPK3PXP')))->toBe(['auth.mfa_enabled']);
    $mfa->saveRecoveryCodes($this->user, ['code-one-1111', 'code-two-2222']);

    expect(AuditTrail::of(fn () => $this->withSession(['login.id' => $this->user->id, 'login.remember' => false])
        ->postJson('/auth/two-factor-challenge', ['code' => '000000'])->assertUnprocessable()))->toBe(['auth.mfa_failed'])
        ->and(AuditTrail::of(fn () => $this->withSession(['login.id' => $this->user->id, 'login.remember' => false])
            ->postJson('/auth/two-factor-challenge', ['recovery_code' => 'code-one-1111'])->assertNoContent()))->toBe(['auth.recovery_code_used', 'auth.login']);

    $this->postJson('/auth/logout');
    $page = Livewire::test(Login::class)->call('usePassword')
        ->fillForm(['email' => 'max@acme.example', 'password' => 'Secret-Passw0rd!'])->call('authenticate')
        ->assertNotSet('userUndertakingMultiFactorAuthentication', null);
    expect(AuditTrail::of(fn () => $page->set('data.multiFactor.app.code', '000000')->call('authenticate')))->toBe(['auth.mfa_failed'])
        ->and(AuditTrail::of(fn () => $mfa->saveSecret($this->user->fresh(), null)))->toBe(['auth.mfa_disabled']);
});

it('writes one entry for a password change and an email verification', function (): void {
    $this->actingAs($this->user);

    expect(AuditTrail::of(fn () => $this->putJson('/auth/user/password', [
        'current_password' => 'Secret-Passw0rd!', 'password' => 'New-Passw0rd!', 'password_confirmation' => 'New-Passw0rd!',
    ])->assertOk()))->toBe(['auth.password_changed']);

    $this->user->forceFill(['email_verified_at' => null])->save();
    $url = URL::temporarySignedRoute('verification.verify', now()->addHour(), ['id' => $this->user->id, 'hash' => sha1($this->user->email)]);
    expect(AuditTrail::of(fn () => $this->actingAs($this->user->fresh())->get($url)))->toBe(['auth.email_verified']);
});

it('keeps a valid TOTP sign-in at one entry', function (): void {
    $mfa = SharedAppAuthentication::make()->recoverable();
    $mfa->saveSecret($this->user, 'JBSWY3DPEHPK3PXP');

    expect(AuditTrail::of(fn () => $this->withSession(['login.id' => $this->user->id, 'login.remember' => false])
        ->postJson('/auth/two-factor-challenge', ['code' => (new Google2FA)->getCurrentOtp('JBSWY3DPEHPK3PXP')])->assertNoContent()))
        ->toBe(['auth.login']);
});

it('writes one lockout entry per throttle window, not per attempt', function (): void {
    foreach (range(1, 5) as $ignored) {
        $this->postJson('/auth/login', ['email' => 'max@acme.example', 'password' => 'wrong']);
    }
    $events = AuditTrail::of(function (): void {
        foreach (range(1, 3) as $ignored) {
            $this->postJson('/auth/login', ['email' => 'max@acme.example', 'password' => 'wrong'])->assertStatus(429);
        }
    });

    expect($events)->toBe(['auth.lockout']);
});
