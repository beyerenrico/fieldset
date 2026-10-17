<?php

declare(strict_types=1);

use App\Domain\Audit\Models\Activity;
use App\Domain\Identity\Actions\AddMember;
use App\Domain\Identity\Actions\InviteMember;
use App\Domain\Identity\Actions\SetMembershipStatus;
use App\Domain\Identity\Enums\MembershipStatus;
use App\Domain\Identity\Enums\SystemRole;
use App\Domain\Identity\Models\ExternalIdentity;
use App\Domain\Identity\Models\Group;
use App\Domain\Identity\Models\IdentityProvider;
use App\Domain\Identity\Models\IdentityProviderMapping;
use App\Domain\Identity\Models\Membership;
use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Sso\Data\ExternalProfile;
use App\Domain\Identity\Sso\Enums\IdentityProviderType;
use App\Domain\Identity\Sso\Enums\SsoClient;
use App\Domain\Identity\Sso\Exceptions\SsoLoginRefused;
use App\Domain\Identity\Sso\Login\CompleteSsoLogin;
use App\Domain\Identity\Tenancy\TenantContext;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\Support\Sso\FakeOidcIdp;

beforeEach(function (): void {
    Notification::fake();
    $this->idp = FakeOidcIdp::bind();
    $this->org = makeOrganization(name: 'Acme GmbH');
    FakeOidcIdp::domain($this->org, 'acme.example');
    $this->provider = $this->idp->provider($this->org);
});

/** @return list<string> */
function jitRoleNames(User $user, int $organizationId): array
{
    return app(TenantContext::class)->run($organizationId, fn (): array => $user->fresh()->roles()->pluck('name')->sort()->values()->all());
}

it('provisions a new user with the default role and group and signs them in once', function (): void {
    $group = app(TenantContext::class)->run($this->org, fn (): Group => Group::factory()->create(['name' => 'Crew North']));
    $reviewer = Role::query()->where('organization_id', $this->org->id)->where('name', 'reviewer')->firstOrFail();
    app(TenantContext::class)->run($this->org, fn () => $this->provider->forceFill(['default_role_id' => $reviewer->id, 'default_group_id' => $group->id])->save());

    $this->idp->login($this->provider, ['sub' => 'kc-1', 'email' => 'Jane@Acme.example', 'name' => 'Jane Doe', 'amr' => ['pwd', 'otp']])
        ->assertRedirect('/app/login?sso=ok');

    $user = User::query()->where('email', 'jane@acme.example')->sole();
    $membership = Membership::query()->where('organization_id', $this->org->id)->where('user_id', $user->id)->sole();
    expect(auth()->id())->toBe($user->id)
        ->and($user->name)->toBe('Jane Doe')
        ->and($user->email_verified_at)->not->toBeNull()
        ->and(Hash::check('password', $user->password))->toBeFalse()
        ->and($membership->status)->toBe(MembershipStatus::Active)
        ->and($membership->default_group_id)->toBe($group->id)
        ->and(jitRoleNames($user, $this->org->id))->toBe(['reviewer'])
        ->and(DB::table('group_user')->where('user_id', $user->id)->pluck('group_id')->all())->toBe([$group->id])
        ->and(ExternalIdentity::query()->withoutTenant()->where('user_id', $user->id)->sole()->subject)->toBe('kc-1');

    $login = Activity::query()->where('event', 'auth.login')->sole();
    expect($login->properties['method'])->toBe('sso')
        ->and($login->properties['provider'])->toBe($this->provider->uuid)
        ->and($login->properties['amr'])->toBe(['pwd', 'otp'])
        ->and($login->properties['remember'])->toBeTrue()
        ->and(Activity::query()->where('event', 'sso.user_provisioned')->sole()->organization_id)->toBe($this->org->id)
        ->and(Activity::query()->where('event', 'sso.identity_linked')->count())->toBe(1);
});

it('uses Field Worker when no default role is set and refuses without JIT', function (): void {
    $this->idp->login($this->provider, ['sub' => 'kc-2', 'email' => 'max@acme.example'])->assertRedirect('/app/login?sso=ok');
    expect(jitRoleNames(User::query()->where('email', 'max@acme.example')->sole(), $this->org->id))->toBe(['field_worker']);

    auth()->logout();
    app(TenantContext::class)->run($this->org, fn () => $this->provider->forceFill(['jit_enabled' => false])->save());
    $this->idp->login($this->provider, ['sub' => 'kc-3', 'email' => 'new@acme.example'])->assertRedirect('/app/login?sso_error=not_member');

    $this->assertGuest();
    expect(User::query()->where('email', 'new@acme.example')->exists())->toBeFalse()
        ->and(Activity::query()->where('event', 'auth.sso_refused')->sole()->properties['reason'])->toBe('not_member');
});

it('returns a linked user by subject on later logins', function (): void {
    $this->idp->login($this->provider, ['sub' => 'kc-4', 'email' => 'eva@acme.example'])->assertRedirect('/app/login?sso=ok');
    auth()->logout();

    $this->idp->login($this->provider, ['sub' => 'kc-4', 'email' => 'eva@acme.example'])->assertRedirect('/app/login?sso=ok');

    expect(User::query()->where('email', 'eva@acme.example')->count())->toBe(1)
        ->and(ExternalIdentity::query()->withoutTenant()->count())->toBe(1)
        ->and(Activity::query()->where('event', 'auth.login')->count())->toBe(2);
});

it('links an existing account of a verified email in a verified domain', function (): void {
    $existing = User::factory()->memberOf($this->org, SystemRole::Designer)->create(['email' => 'lea@acme.example']);

    $this->idp->login($this->provider, ['sub' => 'kc-5', 'email' => 'lea@acme.example'])->assertRedirect('/app/login?sso=ok');

    expect(auth()->id())->toBe($existing->id)
        ->and(jitRoleNames($existing, $this->org->id))->toBe(['designer'])
        ->and(Activity::query()->where('event', 'sso.user_provisioned')->count())->toBe(0);
});

it('refuses to link an unverified email or a domain the organization has not verified', function (): void {
    User::factory()->memberOf($this->org)->create(['email' => 'ben@acme.example']);
    FakeOidcIdp::domain($this->org, 'claimed.example', verified: false);

    $this->idp->login($this->provider, ['sub' => 'kc-6', 'email' => 'ben@acme.example', 'email_verified' => false])
        ->assertRedirect('/app/login?sso_error=email_unverified');
    $this->idp->login($this->provider, ['sub' => 'kc-7', 'email' => 'ben@claimed.example'])
        ->assertRedirect('/app/login?sso_error=domain_not_verified');
    $this->idp->login($this->provider, ['sub' => 'kc-8', 'email' => 'someone@gmail.example'])
        ->assertRedirect('/app/login?sso_error=domain_not_verified');

    $this->assertGuest();
    expect(ExternalIdentity::query()->withoutTenant()->count())->toBe(0);
});

it('refuses deactivated users and memberships and never reactivates them', function (): void {
    $gone = User::factory()->deactivated()->create(['email' => 'gone@acme.example']);
    $left = User::factory()->memberOf($this->org)->create(['email' => 'left@acme.example']);
    $membership = Membership::query()->where('user_id', $left->id)->sole();
    app(SetMembershipStatus::class)->handle($membership, MembershipStatus::Deactivated, ownerOf($this->org));

    $this->idp->login($this->provider, ['sub' => 'kc-9', 'email' => 'gone@acme.example'])->assertRedirect('/app/login?sso_error=account_inactive');
    $this->idp->login($this->provider, ['sub' => 'kc-10', 'email' => 'left@acme.example'])->assertRedirect('/app/login?sso_error=account_inactive');

    $this->assertGuest();
    expect($gone->fresh()->isActive())->toBeFalse()
        ->and($membership->fresh()->status)->toBe(MembershipStatus::Deactivated)
        ->and(ExternalIdentity::query()->withoutTenant()->count())->toBe(0);
});

it('activates an invited membership through SSO', function (): void {
    $membership = app(InviteMember::class)->handle(ownerOf($this->org), $this->org, 'invited@acme.example', 'Invited Person', ['designer']);

    $this->idp->login($this->provider, ['sub' => 'kc-11', 'email' => 'invited@acme.example'])->assertRedirect('/app/login?sso=ok');

    expect($membership->fresh()->status)->toBe(MembershipStatus::Active)
        ->and($membership->user->fresh()->email_verified_at)->not->toBeNull()
        ->and(jitRoleNames($membership->user, $this->org->id))->toBe(['designer'])
        ->and(Activity::query()->where('event', 'member.joined')->sole()->properties['via'])->toBe('sso');
});

it('lets the IdP change the email only within verified domains and to a free address', function (): void {
    FakeOidcIdp::domain($this->org, 'acme-group.example');
    $this->idp->login($this->provider, ['sub' => 'kc-12', 'email' => 'tom@acme.example'])->assertRedirect('/app/login?sso=ok');
    $user = User::query()->where('email', 'tom@acme.example')->sole();
    User::factory()->create(['email' => 'taken@acme.example']);

    foreach (['tom@gmail.example', 'taken@acme.example'] as $refused) {
        $this->idp->login($this->provider, ['sub' => 'kc-12', 'email' => $refused])->assertRedirect('/app/login?sso=ok');
        expect($user->fresh()->email)->toBe('tom@acme.example');
    }

    $this->idp->login($this->provider, ['sub' => 'kc-12', 'email' => 'tom@acme-group.example'])->assertRedirect('/app/login?sso=ok');
    expect($user->fresh()->email)->toBe('tom@acme-group.example');
});

it('keeps a multi-organization user apart: an SSO login touches only that organization', function (): void {
    $other = makeOrganization(name: 'Other GmbH');
    $user = User::factory()->memberOf($this->org, SystemRole::FieldWorker)->create(['email' => 'multi@acme.example']);
    app(AddMember::class)->handle($other, $user, [SystemRole::Admin]);
    $designer = Role::query()->where('organization_id', $this->org->id)->where('name', 'designer')->firstOrFail();
    app(TenantContext::class)->run($this->org, fn () => IdentityProviderMapping::factory()->toRole($designer)
        ->create(['identity_provider_id' => $this->provider->id, 'claim' => 'groups', 'value' => 'designers']));

    $this->idp->login($this->provider, ['sub' => 'kc-13', 'email' => 'multi@acme.example', 'groups' => ['designers']])
        ->assertRedirect('/app/login?sso=ok');

    expect(jitRoleNames($user, $this->org->id))->toBe(['designer', 'field_worker'])
        ->and(jitRoleNames($user, $other->id))->toBe(['admin'])
        ->and(Membership::query()->where('user_id', $user->id)->where('status', MembershipStatus::Active->value)->count())->toBe(2);
});

it('refuses linking through an organization that has not verified the email domain', function (): void {
    $other = makeOrganization(name: 'Other GmbH');
    $user = User::factory()->memberOf($other)->create(['email' => 'multi@acme.example']);
    $otherProvider = app(TenantContext::class)->run($other, fn (): IdentityProvider => IdentityProvider::factory()->active()->create());
    $profile = new ExternalProfile(IdentityProviderType::Oidc, 'https://idp.example.test/realms/acme', 'kc-14', 'multi@acme.example', true, null, []);

    expect(fn () => app(CompleteSsoLogin::class)->handle($otherProvider, $profile))
        ->toThrow(fn (SsoLoginRefused $e) => expect($e->reason->value)->toBe('domain_not_verified'));
    expect(ExternalIdentity::query()->withoutTenant()->where('user_id', $user->id)->exists())->toBeFalse();
});

it('retries once when a concurrent first login wins the unique race', function (): void {
    $failed = false;
    User::creating(function () use (&$failed): void {
        if (! $failed) {
            $failed = true;
            throw new UniqueConstraintViolationException('pgsql', 'insert into users', [], new PDOException('duplicate key'));
        }
    });
    $profile = new ExternalProfile(IdentityProviderType::Oidc, $this->idp->issuer, 'kc-15', 'race@acme.example', true, 'Race', []);

    $user = app(CompleteSsoLogin::class)->handle($this->provider, $profile);

    expect($failed)->toBeTrue()
        ->and($user->email)->toBe('race@acme.example')
        ->and(User::query()->where('email', 'race@acme.example')->count())->toBe(1);
});

it('returns the admin client to the panel and remembers only Field App sessions', function (): void {
    $designer = User::factory()->memberOf($this->org, SystemRole::Designer)->create(['email' => 'des@acme.example']);

    $this->idp->login($this->provider, ['sub' => 'kc-16', 'email' => 'des@acme.example'], SsoClient::Admin)->assertRedirect(url('/admin'));

    expect(auth()->id())->toBe($designer->id)
        ->and(Activity::query()->where('event', 'auth.login')->sole()->properties['remember'])->toBeFalse();
});

it('sends an admin-client user without panel access to the Field App', function (): void {
    $this->idp->login($this->provider, ['sub' => 'kc-17', 'email' => 'worker@acme.example'], SsoClient::Admin)
        ->assertRedirect('/app/login?sso=ok');
});
