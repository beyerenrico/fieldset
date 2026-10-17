<?php

declare(strict_types=1);

use App\Domain\Audit\Models\Activity;
use App\Domain\Identity\Enums\SystemRole;
use App\Domain\Identity\Models\Group;
use App\Domain\Identity\Models\IdentityProviderMapping;
use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Tests\Support\Sso\FakeOidcIdp;

beforeEach(function (): void {
    $this->idp = FakeOidcIdp::bind();
    $this->org = makeOrganization(name: 'Acme GmbH');
    FakeOidcIdp::domain($this->org, 'acme.example');
    $this->provider = $this->idp->provider($this->org);
    $this->tenant = app(TenantContext::class);
    $this->role = fn (string $name): Role => Role::query()->where('organization_id', $this->org->id)->where('name', $name)->firstOrFail();
    $this->map = fn (string $value, array $target, string $claim = 'groups'): IdentityProviderMapping => $this->tenant->run($this->org, fn (): IdentityProviderMapping => IdentityProviderMapping::factory()
        ->create(['identity_provider_id' => $this->provider->id, 'claim' => $claim, 'value' => $value, 'role_id' => null, 'group_id' => null, ...$target]));
});

/** @return list<string> */
function mappedRoles(User $user, int $organizationId): array
{
    return app(TenantContext::class)->run($organizationId, fn (): array => $user->fresh()->roles()->pluck('name')->sort()->values()->all());
}

/** @return list<int> */
function mappedGroups(User $user): array
{
    return DB::table('group_user')->where('user_id', $user->id)->orderBy('group_id')->pluck('group_id')->map(fn (mixed $id): int => (int) $id)->all();
}

it('maps an IdP group to the Designer role at JIT and removes it when the claim no longer carries it', function (): void {
    ($this->map)('kc-designers', ['role_id' => ($this->role)('designer')->id]);

    $this->idp->login($this->provider, ['sub' => 'kc-1', 'email' => 'jane@acme.example', 'groups' => ['kc-designers', 'other']])
        ->assertRedirect('/app/login?sso=ok');
    $user = User::query()->where('email', 'jane@acme.example')->sole();
    expect(mappedRoles($user, $this->org->id))->toBe(['designer', 'field_worker']);

    auth()->logout();
    $this->idp->login($this->provider, ['sub' => 'kc-1', 'email' => 'jane@acme.example', 'groups' => ['other']])->assertRedirect('/app/login?sso=ok');

    expect(mappedRoles($user, $this->org->id))->toBe(['field_worker']);
    $changes = Activity::query()->where('event', 'member.roles_changed')->orderBy('id')->get();
    expect($changes)->toHaveCount(2)
        ->and($changes[0]->properties->all())->toMatchArray(['before' => ['field_worker'], 'after' => ['designer', 'field_worker'], 'source' => 'sso'])
        ->and($changes[1]->properties->all())->toMatchArray(['before' => ['designer', 'field_worker'], 'after' => ['field_worker'], 'source' => 'sso'])
        ->and($changes[1]->actor_type)->toBe('system');
});

it('syncs group targets and leaves manual roles and groups alone', function (): void {
    [$crew, $manual] = $this->tenant->run($this->org, fn (): array => [Group::factory()->create(['name' => 'Crew']), Group::factory()->create(['name' => 'Manual'])]);
    ($this->map)('kc-crew', ['group_id' => $crew->id]);
    ($this->map)('kc-reviewers', ['role_id' => ($this->role)('reviewer')->id]);
    $user = User::factory()->memberOf($this->org, SystemRole::Viewer)->create(['email' => 'max@acme.example']);
    DB::table('group_user')->insert(['group_id' => $manual->id, 'user_id' => $user->id, 'organization_id' => $this->org->id, 'created_at' => now(), 'updated_at' => now()]);

    $this->idp->login($this->provider, ['sub' => 'kc-2', 'email' => 'max@acme.example', 'groups' => ['kc-crew']])->assertRedirect('/app/login?sso=ok');

    expect(mappedRoles($user, $this->org->id))->toBe(['viewer'])
        ->and(mappedGroups($user))->toBe([$crew->id, $manual->id])
        ->and(Activity::query()->where('event', 'member.groups_changed')->sole()->properties->all())
        ->toMatchArray(['before' => [$manual->id], 'after' => [$crew->id, $manual->id], 'source' => 'sso']);
});

it('never touches the Owner role, even when a mapping names it', function (): void {
    $owner = ownerOf($this->org);
    $owner->forceFill(['email' => 'boss@acme.example'])->save();
    ($this->map)('kc-owners', ['role_id' => ($this->role)('owner')->id]);
    ($this->map)('kc-designers', ['role_id' => ($this->role)('designer')->id]);

    $this->idp->login($this->provider, ['sub' => 'kc-3', 'email' => 'boss@acme.example', 'groups' => []])->assertRedirect('/app/login?sso=ok');
    expect(mappedRoles($owner, $this->org->id))->toBe(['owner']);

    auth()->logout();
    $this->idp->login($this->provider, ['sub' => 'kc-4', 'email' => 'new@acme.example', 'groups' => ['kc-owners']])->assertRedirect('/app/login?sso=ok');
    expect(mappedRoles(User::query()->where('email', 'new@acme.example')->sole(), $this->org->id))->toBe(['field_worker']);
});

it('treats an absent groups claim as no groups (IdPs omit empty ones) and audits it', function (): void {
    ($this->map)('kc-designers', ['role_id' => ($this->role)('designer')->id]);
    $this->idp->login($this->provider, ['sub' => 'kc-5', 'email' => 'lea@acme.example', 'groups' => ['kc-designers']])->assertRedirect('/app/login?sso=ok');
    $user = User::query()->where('email', 'lea@acme.example')->sole();
    auth()->logout();

    $this->idp->login($this->provider, ['sub' => 'kc-5', 'email' => 'lea@acme.example'])->assertRedirect('/app/login?sso=ok');

    expect(mappedRoles($user, $this->org->id))->toBe(['field_worker'])
        ->and(Activity::query()->where('event', 'sso.mapping_claim_missing')->sole()->properties->all())
        ->toMatchArray(['claim' => 'groups', 'provider' => $this->provider->uuid, 'treated_as' => 'empty']);
});

it('skips the targets of any other missing claim and audits it', function (): void {
    ($this->map)('approver', ['role_id' => ($this->role)('reviewer')->id], 'department_role');
    $this->idp->login($this->provider, ['sub' => 'kc-7', 'email' => 'ina@acme.example', 'department_role' => 'approver'])->assertRedirect('/app/login?sso=ok');
    $user = User::query()->where('email', 'ina@acme.example')->sole();
    auth()->logout();

    $this->idp->login($this->provider, ['sub' => 'kc-7', 'email' => 'ina@acme.example'])->assertRedirect('/app/login?sso=ok');

    expect(mappedRoles($user, $this->org->id))->toBe(['field_worker', 'reviewer'])
        ->and(Activity::query()->where('event', 'sso.mapping_claim_missing')->sole()->properties->all())
        ->toMatchArray(['claim' => 'department_role', 'treated_as' => 'skipped']);
});

it('reads a renamed groups claim and treats it as empty when absent', function (): void {
    app(TenantContext::class)->run($this->org, fn () => $this->provider->forceFill(['config' => [...$this->provider->config, 'groups_claim' => 'roles']])->save());
    ($this->map)('kc-designers', ['role_id' => ($this->role)('designer')->id]);

    $this->idp->login($this->provider, ['sub' => 'kc-8', 'email' => 'ole@acme.example', 'roles' => ['kc-designers']])->assertRedirect('/app/login?sso=ok');
    $user = User::query()->where('email', 'ole@acme.example')->sole();
    expect(mappedRoles($user, $this->org->id))->toBe(['designer', 'field_worker']);
    auth()->logout();

    $this->idp->login($this->provider, ['sub' => 'kc-8', 'email' => 'ole@acme.example'])->assertRedirect('/app/login?sso=ok');
    expect(mappedRoles($user, $this->org->id))->toBe(['field_worker']);
});

it('reads mappings on any claim the provider names, including a single string value', function (): void {
    ($this->map)('approver', ['role_id' => ($this->role)('reviewer')->id], 'department_role');

    $this->idp->login($this->provider, ['sub' => 'kc-6', 'email' => 'tom@acme.example', 'department_role' => 'approver'])->assertRedirect('/app/login?sso=ok');

    expect(mappedRoles(User::query()->where('email', 'tom@acme.example')->sole(), $this->org->id))->toBe(['field_worker', 'reviewer']);
});
