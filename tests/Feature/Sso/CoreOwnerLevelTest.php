<?php

declare(strict_types=1);

use App\Domain\Identity\Enums\Permission;
use App\Domain\Identity\Enums\SystemRole;
use App\Domain\Identity\Models\IdentityProviderMapping;
use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Tenancy\TenantContext;
use Tests\Support\Sso\FakeOidcIdp;

/*
 * Gate review M7, finding 7: at login SSO never grants or removes an Owner-level role — Owner or any role
 * with organization.delete (SsoTargets::isOwnerLevel) — even when a stale or hand-made mapping or the JIT
 * default names one.
 */
beforeEach(function (): void {
    $this->idp = FakeOidcIdp::bind();
    $this->org = makeOrganization(name: 'Acme GmbH');
    FakeOidcIdp::domain($this->org, 'acme.example');
    $this->provider = $this->idp->provider($this->org);
    $this->tenant = app(TenantContext::class);
    $this->deputy = Role::create(['name' => 'Deputy owner', 'guard_name' => 'web', 'organization_id' => $this->org->id]);
    $this->deputy->givePermissionTo(Permission::OrganizationDelete->value);
    $this->tenant->run($this->org, fn () => IdentityProviderMapping::factory()->toRole($this->deputy)
        ->create(['identity_provider_id' => $this->provider->id, 'claim' => 'groups', 'value' => 'kc-deputies']));
});

/** @return list<string> */
function coreOwnerLevelRoles(User $user, int $organizationId): array
{
    return app(TenantContext::class)->run($organizationId, fn (): array => $user->fresh()->roles()->pluck('name')->sort()->values()->all());
}

it('never grants an Owner-level custom role through a mapping', function (): void {
    $this->idp->login($this->provider, ['sub' => 'kc-1', 'email' => 'eve@acme.example', 'groups' => ['kc-deputies']])->assertRedirect('/app/login?sso=ok');

    expect(coreOwnerLevelRoles(User::query()->where('email', 'eve@acme.example')->sole(), $this->org->id))->toBe(['field_worker']);
});

it('never removes an Owner-level custom role held manually', function (): void {
    $user = User::factory()->memberOf($this->org, SystemRole::Viewer)->create(['email' => 'dan@acme.example']);
    $this->tenant->run($this->org, fn () => $user->assignRole($this->deputy));

    $this->idp->login($this->provider, ['sub' => 'kc-2', 'email' => 'dan@acme.example', 'groups' => []])->assertRedirect('/app/login?sso=ok');

    expect(coreOwnerLevelRoles($user, $this->org->id))->toBe(['Deputy owner', 'viewer']);
});

it('falls back to Field Worker when the JIT default role is Owner-level', function (): void {
    $this->tenant->run($this->org, fn () => $this->provider->forceFill(['default_role_id' => $this->deputy->id])->save());

    $this->idp->login($this->provider, ['sub' => 'kc-3', 'email' => 'fay@acme.example'])->assertRedirect('/app/login?sso=ok');

    expect(coreOwnerLevelRoles(User::query()->where('email', 'fay@acme.example')->sole(), $this->org->id))->toBe(['field_worker']);
});
