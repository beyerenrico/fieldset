<?php

declare(strict_types=1);

use App\Domain\Audit\Models\Activity;
use App\Domain\Identity\Enums\Permission;
use App\Domain\Identity\Enums\SystemRole;
use App\Domain\Identity\Models\Group;
use App\Domain\Identity\Models\IdentityProvider;
use App\Domain\Identity\Models\IdentityProviderMapping;
use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Sso\Admin\DeleteClaimMapping;
use App\Domain\Identity\Sso\Admin\SaveClaimMapping;
use App\Domain\Identity\Tenancy\TenantContext;
use Illuminate\Validation\ValidationException;

/** @return array<string, list<string>> */
function ssoAdminMappingErrors(array $input, ?User $actor = null): array
{
    try {
        app(SaveClaimMapping::class)->handle(test()->provider, $input, $actor ?? test()->owner);
    } catch (ValidationException $e) {
        return $e->errors();
    }
    test()->fail('expected a validation error');
}

function ssoAdminOrgRole(SystemRole $role): Role
{
    return Role::query()->where('organization_id', test()->org->id)->where('name', $role->value)->sole();
}

beforeEach(function (): void {
    $this->org = makeOrganization();
    $this->owner = ownerOf($this->org);
    $this->tenant = app(TenantContext::class);
    $this->tenant->set($this->org);
    $this->provider = IdentityProvider::factory()->create();
    $this->group = Group::factory()->create();
});

it('maps a groups claim value to a role or a group, defaulting the claim to groups', function (): void {
    $toRole = app(SaveClaimMapping::class)->handle($this->provider, ['claim' => '', 'value' => ' reviewers ', 'role_id' => ssoAdminOrgRole(SystemRole::Reviewer)->id], $this->owner);
    $toGroup = app(SaveClaimMapping::class)->handle($this->provider, ['claim' => 'roles', 'value' => 'crew-north', 'group_id' => (string) $this->group->id], $this->owner);

    expect($toRole)->claim->toBe('groups')->value->toBe('reviewers')->group_id->toBeNull()
        ->and($toGroup)->claim->toBe('roles')->group_id->toBe($this->group->id)->role_id->toBeNull()
        ->and(Activity::query()->where('subject_type', (new IdentityProviderMapping)->getMorphClass())->where('event', 'created')->pluck('causer_id')->all())
        ->toBe([$this->owner->id, $this->owner->id]);

    $updated = app(SaveClaimMapping::class)->handle($this->provider, ['value' => 'crew-south', 'group_id' => $this->group->id], $this->owner, $toGroup);
    expect($updated->id)->toBe($toGroup->id)->and($updated->value)->toBe('crew-south')->and($updated->claim)->toBe('groups');
});

it('requires a role xor a group', function (): void {
    expect(ssoAdminMappingErrors(['value' => 'x']))->toHaveKey('target')
        ->and(ssoAdminMappingErrors(['value' => 'x', 'role_id' => ssoAdminOrgRole(SystemRole::Viewer)->id, 'group_id' => $this->group->id]))->toHaveKey('target')
        ->and(ssoAdminMappingErrors(['value' => '', 'group_id' => $this->group->id]))->toHaveKey('value')
        ->and(ssoAdminMappingErrors(['claim' => 'bad claim', 'value' => 'x', 'group_id' => $this->group->id]))->toHaveKey('claim');
});

it('never maps to Owner or an Owner-level custom role', function (): void {
    $custom = Role::create(['name' => 'Deputy owner', 'guard_name' => 'web', 'organization_id' => $this->org->id]);
    $custom->givePermissionTo(Permission::OrganizationDelete->value);

    expect(ssoAdminMappingErrors(['value' => 'x', 'role_id' => ssoAdminOrgRole(SystemRole::Owner)->id]))->toHaveKey('role_id')
        ->and(ssoAdminMappingErrors(['value' => 'x', 'role_id' => $custom->id]))->toHaveKey('role_id');
});

it('refuses roles and groups of another organization', function (): void {
    $other = makeOrganization(name: 'Other');
    $foreignRole = Role::query()->where('organization_id', $other->id)->where('name', SystemRole::Viewer->value)->sole();
    $foreignGroup = $this->tenant->run($other, fn () => Group::factory()->create());
    $this->tenant->set($this->org);

    expect(ssoAdminMappingErrors(['value' => 'x', 'role_id' => $foreignRole->id]))->toHaveKey('role_id')
        ->and(ssoAdminMappingErrors(['value' => 'x', 'group_id' => $foreignGroup->id]))->toHaveKey('group_id');
});

it('lets an Admin map only roles they could assign themselves', function (): void {
    $admin = memberOf($this->org, SystemRole::Admin);

    expect(app(SaveClaimMapping::class)->handle($this->provider, ['value' => 'designers', 'role_id' => ssoAdminOrgRole(SystemRole::Designer)->id], $admin)->exists)->toBeTrue()
        ->and(ssoAdminMappingErrors(['value' => 'owners', 'role_id' => ssoAdminOrgRole(SystemRole::Owner)->id], $admin))->toHaveKey('role_id');
});

it('refuses a duplicate mapping', function (): void {
    $input = ['value' => 'crew', 'group_id' => $this->group->id];
    app(SaveClaimMapping::class)->handle($this->provider, $input, $this->owner);

    expect(ssoAdminMappingErrors($input))->toHaveKey('value');
});

it('deletes a mapping with an audit entry naming the actor', function (): void {
    $mapping = app(SaveClaimMapping::class)->handle($this->provider, ['value' => 'crew', 'group_id' => $this->group->id], $this->owner);
    $this->tenant->clear();

    app(DeleteClaimMapping::class)->handle($mapping, $this->owner);

    $entry = Activity::query()->where('subject_type', (new IdentityProviderMapping)->getMorphClass())->where('event', 'deleted')->sole();
    expect(IdentityProviderMapping::query()->withoutTenant()->count())->toBe(0)
        ->and($entry->causer_id)->toBe($this->owner->id)
        ->and($entry->organization_id)->toBe($this->org->id);
});
