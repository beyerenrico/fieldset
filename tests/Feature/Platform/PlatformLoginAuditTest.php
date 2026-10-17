<?php

declare(strict_types=1);

use App\Domain\Audit\Models\Activity;
use App\Domain\Identity\Models\IdentityProvider;
use App\Domain\Identity\Models\SsoDomain;
use App\Domain\Identity\Tenancy\TenantContext;
use App\Domain\Platform\Auth\PlatformSession;
use App\Filament\Platform\Pages\Auth\Login;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\Support\AuditTrail;

require_once __DIR__.'/Support.php';

/*
 * /platform/login (ADR 0041) audits like the admin login (§8.11): throttled attempts (`auth.lockout`, once per
 * window) and wrong MFA codes (`auth.mfa_failed`), with via=platform. An address whose domain enforces SSO is
 * refused before any password check (ADR 0036), except the organization's valid break-glass Owner.
 */

beforeEach(function (): void {
    AuditTrail::reset();
    Filament::setCurrentPanel('platform');
    $this->operator = platformOperator();
    $this->operator->forceFill(['email' => 'olga@ops.example', 'password' => 'correct-horse-battery'])->save();
});

function platformLoginEntry(string $event): Activity
{
    return Activity::query()->where('event', $event)->sole();
}

it('audits a throttled sign-in once per window as auth.lockout via platform', function (): void {
    $page = Livewire::test(Login::class);
    foreach (range(1, 5) as $ignored) {
        $page->fillForm(['email' => 'olga@ops.example', 'password' => 'wrong'])->call('authenticate');
    }

    $events = AuditTrail::of(function () use ($page): void {
        $page->fillForm(['email' => 'olga@ops.example', 'password' => 'wrong'])->call('authenticate');
        $page->fillForm(['email' => 'olga@ops.example', 'password' => 'wrong'])->call('authenticate');
    });

    expect($events)->toBe(['auth.lockout'])
        ->and(platformLoginEntry('auth.lockout')->properties['via'])->toBe('platform')
        ->and(platformLoginEntry('auth.lockout')->properties['email'])->toBe('olga@ops.example')
        ->and(auth()->check())->toBeFalse();
});

it('audits a wrong MFA code as auth.mfa_failed via platform', function (): void {
    $page = Livewire::test(Login::class)
        ->fillForm(['email' => 'olga@ops.example', 'password' => 'correct-horse-battery'])->call('authenticate')
        ->assertNotSet('userUndertakingMultiFactorAuthentication', null);

    expect(AuditTrail::of(fn () => $page->set('data.multiFactor.app.code', '000000')->call('authenticate')))->toBe(['auth.mfa_failed']);

    $entry = platformLoginEntry('auth.mfa_failed');
    expect($entry->properties['via'])->toBe('platform')
        ->and($entry->causer_id)->toBe($this->operator->id)
        ->and(auth()->check())->toBeFalse()
        ->and(app(PlatformSession::class)->isValid($this->operator))->toBeFalse();
});

describe('a domain that enforces SSO', function (): void {
    beforeEach(function (): void {
        $this->org = makeOrganization(name: 'Ops GmbH');
        $this->breakGlass = ownerOf($this->org);
        $this->breakGlass->forceFill(['email' => 'owner@ops.example', 'password' => 'owner-pass-123'])->save();
        $this->breakGlass = platformOperatorGrant($this->breakGlass);
        app(TenantContext::class)->run($this->org, function (): void {
            SsoDomain::factory()->verified()->create(['domain' => 'ops.example']);
            IdentityProvider::factory()->enforcing($this->breakGlass)->create();
        });
    });

    it('refuses an operator password sign-in with the error on the email field and audits it', function (): void {
        Livewire::test(Login::class)
            ->fillForm(['email' => 'olga@ops.example', 'password' => 'correct-horse-battery'])->call('authenticate')
            ->assertHasFormErrors(['email' => __('platform.login.sso_required')])
            ->assertSet('userUndertakingMultiFactorAuthentication', null);

        $entry = platformLoginEntry('auth.password_login_blocked');
        expect($entry->properties['via'])->toBe('platform')
            ->and($entry->properties['email'])->toBe('olga@ops.example')
            ->and(auth()->check())->toBeFalse()
            ->and(app(PlatformSession::class)->isValid($this->operator))->toBeFalse();
    });

    it('lets the valid break-glass Owner through to the MFA challenge', function (): void {
        Livewire::test(Login::class)
            ->fillForm(['email' => 'owner@ops.example', 'password' => 'owner-pass-123'])->call('authenticate')
            ->assertHasNoFormErrors()
            ->assertNotSet('userUndertakingMultiFactorAuthentication', null);

        expect(Activity::query()->where('event', 'auth.password_login_blocked')->count())->toBe(0);
    });
});
