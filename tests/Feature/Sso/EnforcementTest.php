<?php

declare(strict_types=1);

use App\Domain\Audit\Models\Activity;
use App\Domain\Identity\Actions\AddMember;
use App\Domain\Identity\Actions\InviteMember;
use App\Domain\Identity\Auth\SharedAppAuthentication;
use App\Domain\Identity\Enums\MembershipStatus;
use App\Domain\Identity\Enums\SystemRole;
use App\Domain\Identity\Models\IdentityProvider;
use App\Domain\Identity\Models\SsoDomain;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Sso\Contracts\PasswordAuthGate;
use App\Domain\Identity\Sso\Contracts\SsoMisconfigurationReporter;
use App\Domain\Identity\Sso\Enforcement\DomainPasswordGate;
use App\Domain\Identity\Sso\Enums\IdentityProviderStatus;
use App\Domain\Identity\Sso\Enums\SsoClient;
use App\Domain\Identity\Sso\Exceptions\SsoRequiredException;
use App\Domain\Identity\Tenancy\TenantContext;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\ResetsUserPasswords;
use PragmaRX\Google2FA\Google2FA;

beforeEach(function (): void {
    Notification::fake();
    $this->reports = [];
    $reports = &$this->reports;
    app()->instance(SsoMisconfigurationReporter::class, new class($reports) implements SsoMisconfigurationReporter
    {
        /** @param  list<string>  $reports */
        public function __construct(private array &$reports) {}

        public function report(IdentityProvider $p, string $reason, string $detail): void
        {
            $this->reports[] = $reason;
        }
    });

    $this->org = makeOrganization(name: 'Acme GmbH');
    $this->owner = ownerOf($this->org);
    $this->owner->forceFill(['email' => 'owner@acme.example', 'password' => 'owner-pass-123'])->save();
    $this->tenant = app(TenantContext::class);
    $this->domain = $this->tenant->run($this->org, fn (): SsoDomain => SsoDomain::factory()->verified()->create(['domain' => 'acme.example']));
    $this->provider = $this->tenant->run($this->org, fn (): IdentityProvider => IdentityProvider::factory()->enforcing($this->owner)->create());
    $this->worker = User::factory()->memberOf($this->org)->create(['email' => 'worker@acme.example', 'password' => 'worker-pass-123']);
});

function enrollMfa(User $user): string
{
    $mfa = SharedAppAuthentication::make()->recoverable();
    $secret = $mfa->generateSecret();
    $mfa->saveSecret($user, $secret);

    return $secret;
}

it('refuses a Fortify password login with 422 sso_required before any password check', function (string $password): void {
    $this->postJson('/auth/login', ['email' => 'Worker@Acme.example', 'password' => $password])
        ->assertUnprocessable()
        ->assertJsonPath('errors.sso_required.0', __('sso.errors.sso_required'))
        ->assertJsonMissingPath('errors.email');

    $this->assertGuest();
    $blocked = Activity::query()->where('event', 'auth.password_login_blocked')->sole();
    expect($blocked->properties->all())->toMatchArray(['email' => 'worker@acme.example', 'via' => 'fortify'])
        ->and($blocked->organization_id)->toBe($this->org->id)
        ->and($blocked->causer_id)->toBe($this->worker->id)
        ->and(Activity::query()->where('event', 'auth.failed')->count())->toBe(0);
})->with(['correct password' => 'worker-pass-123', 'wrong password' => 'nope']);

it('refuses forgot-password and a reset with a token issued before enforcement', function (): void {
    $this->tenant->run($this->org, fn () => $this->provider->forceFill(['enforce_sso' => false])->save());
    $token = Password::broker()->createToken($this->worker);
    $this->tenant->run($this->org, fn () => $this->provider->forceFill(['enforce_sso' => true])->save());

    $this->postJson('/auth/forgot-password', ['email' => 'worker@acme.example'])->assertUnprocessable()->assertJsonValidationErrors('sso_required');
    $this->postJson('/auth/reset-password', [
        'token' => $token, 'email' => 'worker@acme.example', 'password' => 'N3w-Secret-Pass!', 'password_confirmation' => 'N3w-Secret-Pass!',
    ])->assertUnprocessable()->assertJsonValidationErrors('sso_required');

    Notification::assertNothingSent();
    expect(Hash::check('worker-pass-123', $this->worker->fresh()->password))->toBeTrue()
        ->and(Activity::query()->where('event', 'auth.password_reset_blocked')->pluck('properties')->pluck('via')->sort()->values()->all())
        ->toBe(['forgot', 'reset']);
});

it('refuses the reset in the action itself (backstop)', function (): void {
    $action = app(ResetsUserPasswords::class);

    expect(fn () => $action->reset($this->worker, ['password' => 'N3w-Secret-Pass!', 'password_confirmation' => 'N3w-Secret-Pass!']))
        ->toThrow(ValidationException::class);
    expect(Hash::check('worker-pass-123', $this->worker->fresh()->password))->toBeTrue();
});

it('sends an invited new account to SSO instead of letting it set a password', function (): void {
    $membership = app(InviteMember::class)->handle($this->owner, $this->org, 'new@acme.example', 'New Person', ['field_worker']);
    $url = URL::temporarySignedRoute('invitations.show', now()->addDay(), ['membership' => $membership->id]);
    $sso = $this->provider->redirectUrl(SsoClient::App, 'new@acme.example').'&start=';

    expect($this->get($url)->assertRedirect()->headers->get('Location'))->toStartWith($sso)->toMatch('/&start=[A-Za-z0-9]{40}$/');
    expect($this->post($url, ['password' => 'Sup3r-Secret-Pw!', 'password_confirmation' => 'Sup3r-Secret-Pw!'])->assertStatus(303)->headers->get('Location'))
        ->toStartWith($sso)->toMatch('/&start=[A-Za-z0-9]{40}$/');

    $this->assertGuest();
    expect($membership->fresh()->status)->toBe(MembershipStatus::Invited)
        ->and(Activity::query()->where('event', 'auth.password_login_blocked')->sole()->properties['via'])->toBe('invitation');
});

it('lets the break-glass Owner with MFA through to the MFA challenge and audits the use', function (): void {
    $secret = enrollMfa($this->owner);

    $this->postJson('/auth/login', ['email' => 'owner@acme.example', 'password' => 'owner-pass-123'])
        ->assertOk()->assertJson(['two_factor' => true]);
    $this->assertGuest();
    $this->postJson('/auth/two-factor-challenge', ['code' => (new Google2FA)->getCurrentOtp($secret)])->assertNoContent();

    $this->assertAuthenticatedAs($this->owner);
    expect(Activity::query()->where('event', 'auth.login')->sole()->properties['method'])->toBe('break_glass')
        ->and(Activity::query()->where('event', 'auth.break_glass_login')->sole()->causer_id)->toBe($this->owner->id)
        ->and(app(PasswordAuthGate::class)->isBreakGlass($this->owner->fresh()))->toBeTrue();
});

it('refuses the break-glass Owner without MFA and reports the misconfiguration', function (): void {
    $this->postJson('/auth/login', ['email' => 'owner@acme.example', 'password' => 'owner-pass-123'])
        ->assertUnprocessable()->assertJsonValidationErrors('sso_required');

    $this->assertGuest();
    expect($this->reports)->toBe(['break_glass_invalid'])
        ->and(app(PasswordAuthGate::class)->isBreakGlass($this->owner->fresh()))->toBeFalse();
});

it('refuses a break-glass user who is no longer an active Owner', function (): void {
    enrollMfa($this->owner);
    app(AddMember::class)->handle($this->org, $this->owner, [SystemRole::Admin]);

    expect(fn () => app(PasswordAuthGate::class)->assertPasswordLogin('owner@acme.example'))->toThrow(SsoRequiredException::class)
        ->and($this->reports)->toBe(['break_glass_invalid']);
});

it('applies enforcement by domain in every organization of the person', function (): void {
    $other = makeOrganization(name: 'Other GmbH');
    $guest = User::factory()->memberOf($other, SystemRole::Admin)->create(['email' => 'guest@acme.example', 'password' => 'guest-pass-123']);
    $outsider = User::factory()->memberOf($this->org)->create(['email' => 'freelancer@gmail.example', 'password' => 'free-pass-123']);

    $this->postJson('/auth/login', ['email' => $guest->email, 'password' => 'guest-pass-123'])->assertJsonValidationErrors('sso_required');
    $this->postJson('/auth/login', ['email' => $outsider->email, 'password' => 'free-pass-123'])->assertOk();
});

it('lifts enforcement when the domain is unverified or the provider disabled', function (): void {
    $this->tenant->run($this->org, fn () => $this->domain->forceFill(['verified_at' => null])->save());
    $this->postJson('/auth/login', ['email' => 'worker@acme.example', 'password' => 'worker-pass-123'])->assertOk();
    auth()->guard('web')->logout();

    $this->tenant->run($this->org, function (): void {
        $this->domain->forceFill(['verified_at' => now()])->save();
        $this->provider->forceFill(['status' => IdentityProviderStatus::Disabled])->save();
    });
    app(DomainPasswordGate::class)->assertPasswordLogin('worker@acme.example');
    app(DomainPasswordGate::class)->assertPasswordReset('worker@acme.example');
    $this->postJson('/auth/forgot-password', ['email' => 'worker@acme.example'])->assertOk();
    Notification::assertSentTo($this->worker, ResetPassword::class);
});

it('keeps enforcement while the provider is in error (the IdP may be down)', function (): void {
    $this->tenant->run($this->org, fn () => $this->provider->forceFill(['status' => IdentityProviderStatus::Error])->save());

    expect(fn () => app(PasswordAuthGate::class)->assertPasswordLogin('worker@acme.example'))
        ->toThrow(fn (SsoRequiredException $e) => expect($e->discovery->provider?->id)->toBe($this->provider->id)->and($e->discovery->enforced)->toBeTrue());
});
