<?php

declare(strict_types=1);

use App\Domain\Audit\Models\Activity;
use App\Domain\Identity\Enums\MembershipStatus;
use App\Domain\Identity\Enums\SystemRole;
use App\Domain\Identity\Enums\UserStatus;
use App\Domain\Identity\Models\ExternalIdentity;
use App\Domain\Identity\Models\IdentityProvider;
use App\Domain\Identity\Models\Membership;
use App\Domain\Identity\Models\SsoDomain;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Sso\Admin\BreakGlassOwners;
use App\Domain\Identity\Sso\Admin\SetSsoEnforcement;
use App\Domain\Identity\Sso\Enums\IdentityProviderStatus;
use App\Domain\Identity\Tenancy\TenantContext;
use Illuminate\Validation\ValidationException;

function ssoAdminWithMfa(User $user): User
{
    $user->forceFill(['two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'), 'two_factor_confirmed_at' => now()])->save();

    return $user->refresh();
}

/** @return array<string, list<string>> */
function ssoAdminEnforcementErrors(IdentityProvider $provider, ?User $breakGlass, User $actor): array
{
    try {
        app(SetSsoEnforcement::class)->handle($provider, true, $breakGlass, $actor);
    } catch (ValidationException $e) {
        return $e->errors();
    }
    test()->fail('expected a validation error');
}

beforeEach(function (): void {
    $this->org = makeOrganization();
    $this->owner = ssoAdminWithMfa(ownerOf($this->org));
    $this->tenant = app(TenantContext::class);
    $this->tenant->set($this->org);
    $this->provider = IdentityProvider::factory()->active()->create();
    SsoDomain::factory()->verified()->create(['domain' => 'acme.example']);
});

it('switches enforcement on with a valid break-glass Owner and audits the change', function (): void {
    app(SetSsoEnforcement::class)->handle($this->provider, true, $this->owner, $this->owner);

    $entry = Activity::query()->where('event', 'sso.enforcement_changed')->sole();
    expect($this->provider->refresh())->enforce_sso->toBeTrue()->break_glass_user_id->toBe($this->owner->id)
        ->and($entry->properties['before'])->toBe(['enforce_sso' => false, 'break_glass_user_id' => null])
        ->and($entry->properties['after'])->toBe(['enforce_sso' => true, 'break_glass_user_id' => $this->owner->id])
        ->and($entry->causer_id)->toBe($this->owner->id);

    app(SetSsoEnforcement::class)->handle($this->provider, true, $this->owner, $this->owner); // unchanged: no entry
    app(SetSsoEnforcement::class)->handle($this->provider, false, null, $this->owner);
    expect($this->provider->refresh())->enforce_sso->toBeFalse()->break_glass_user_id->toBe($this->owner->id)
        ->and(Activity::query()->where('event', 'sso.enforcement_changed')->count())->toBe(2);
});

it('requires a verified domain and an active provider', function (): void {
    SsoDomain::query()->update(['verified_at' => null]);
    $this->provider->forceFill(['status' => IdentityProviderStatus::Draft])->save();

    $errors = ssoAdminEnforcementErrors($this->provider, $this->owner, $this->owner);
    expect($errors['enforce_sso'])->toBe([
        __('sso_admin.enforcement.errors.no_verified_domain'),
        __('sso_admin.enforcement.errors.provider_not_active'),
    ])->and($this->provider->refresh()->enforce_sso)->toBeFalse();
});

it('requires an active Owner with confirmed MFA as break-glass account', function (): void {
    $admin = ssoAdminWithMfa(memberOf($this->org, SystemRole::Admin));
    $ownerWithoutMfa = memberOf($this->org, SystemRole::Owner);
    $deactivated = ssoAdminWithMfa(memberOf($this->org, SystemRole::Owner));
    $deactivated->forceFill(['status' => UserStatus::Deactivated])->save();
    $suspended = ssoAdminWithMfa(memberOf($this->org, SystemRole::Owner));
    Membership::query()->where('user_id', $suspended->id)->update(['status' => MembershipStatus::Deactivated->value]);
    $foreignOwner = ssoAdminWithMfa(ownerOf(makeOrganization(name: 'Other')));
    $this->tenant->set($this->org);

    expect(ssoAdminEnforcementErrors($this->provider, null, $this->owner)['break_glass_user_id'])->toBe([__('sso_admin.enforcement.errors.break_glass_required')]);
    foreach ([$admin, $deactivated, $suspended, $foreignOwner] as $candidate) {
        expect(ssoAdminEnforcementErrors($this->provider, $candidate, $this->owner)['break_glass_user_id'])->toBe([__('sso_admin.enforcement.errors.break_glass_not_owner')]);
    }
    expect(ssoAdminEnforcementErrors($this->provider, $ownerWithoutMfa, $this->owner)['break_glass_user_id'])->toBe([__('sso_admin.enforcement.errors.break_glass_no_mfa')]);
    expect(app(BreakGlassOwners::class)->owners($this->org)->pluck('id')->sort()->values()->all())
        ->toBe(collect([$this->owner->id, $ownerWithoutMfa->id])->sort()->values()->all());
});

it('prevents the actor from locking themselves out', function (): void {
    $admin = memberOf($this->org, SystemRole::Admin);

    expect(ssoAdminEnforcementErrors($this->provider, $this->owner, $admin)['enforce_sso'])->toBe([__('sso_admin.enforcement.errors.self_lockout')]);

    ExternalIdentity::factory()->create(['identity_provider_id' => $this->provider->id, 'user_id' => $admin->id]);
    app(SetSsoEnforcement::class)->handle($this->provider, true, $this->owner, $admin);
    expect($this->provider->refresh()->enforce_sso)->toBeTrue();
});

it('validates a new break-glass Owner while enforcement stays on', function (): void {
    app(SetSsoEnforcement::class)->handle($this->provider, true, $this->owner, $this->owner);
    $newOwner = memberOf($this->org, SystemRole::Owner);

    expect(ssoAdminEnforcementErrors($this->provider, $newOwner, $this->owner))->toHaveKey('break_glass_user_id')
        ->and($this->provider->refresh()->break_glass_user_id)->toBe($this->owner->id);
});
