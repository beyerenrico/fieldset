<?php

declare(strict_types=1);

use App\Domain\Identity\Enums\MembershipStatus;
use App\Domain\Identity\Enums\Permission;
use App\Domain\Identity\Enums\SystemRole;
use App\Domain\Identity\Models\Device;
use App\Domain\Identity\Models\Group;
use App\Domain\Identity\Models\Membership;
use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Notifications\MemberInvitation;
use App\Filament\Admin\Resources\Groups\Pages\ManageGroups;
use App\Filament\Admin\Resources\Members\Pages\ManageMembers;
use App\Filament\Admin\Resources\Roles\Pages\ManageRoles;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->org = makeOrganization();
    $this->owner = ownerOf($this->org);
});

it('invites a member with roles and groups and mails a signed link', function (): void {
    Notification::fake();
    actingInAdmin($this->owner, $this->org);
    $crew = Group::query()->create(['name' => 'Crew North']);

    Livewire::test(ManageMembers::class)
        ->callAction('invite', ['email' => 'Worker@Example.com', 'name' => 'Wanda Worker', 'roles' => ['field_worker'], 'groups' => [$crew->id]])
        ->assertHasNoActionErrors();

    $user = User::query()->where('email', 'worker@example.com')->sole();
    $membership = Membership::query()->where('user_id', $user->id)->sole();
    expect($membership->status)->toBe(MembershipStatus::Invited)
        ->and($user->hasPermissionIn($this->org, Permission::FormsFill))->toBeTrue()
        ->and($user->groups()->withoutTenant()->pluck('groups.id')->all())->toBe([$crew->id]);
    Notification::assertSentTo($user, MemberInvitation::class);
});

it('edits roles and groups; group removal applies immediately', function (): void {
    actingInAdmin($this->owner, $this->org);
    $worker = memberOf($this->org, SystemRole::FieldWorker);
    $crew = Group::query()->create(['name' => 'Crew']);
    $membership = Membership::query()->where('user_id', $worker->id)->sole();

    Livewire::test(ManageMembers::class)
        ->callTableAction('edit', $membership, ['roles' => ['reviewer'], 'groups' => [$crew->id], 'default_group_id' => $crew->id])
        ->assertHasNoTableActionErrors();
    expect($worker->hasPermissionIn($this->org, Permission::SubmissionsReview))->toBeTrue()
        ->and($membership->fresh()->default_group_id)->toBe($crew->id);

    Livewire::test(ManageMembers::class)->callTableAction('edit', $membership, ['roles' => ['reviewer'], 'groups' => []]);
    expect(DB::table('group_user')->where('user_id', $worker->id)->count())->toBe(0);
});

it('deactivates a member, revokes tokens and protects the last owner', function (): void {
    actingInAdmin($this->owner, $this->org);
    $worker = memberOf($this->org, SystemRole::FieldWorker);
    $worker->createToken('integration')->accessToken->forceFill(['organization_id' => $this->org->id])->save();
    $membership = Membership::query()->where('user_id', $worker->id)->sole();

    Livewire::test(ManageMembers::class)->callTableAction('toggleStatus', $membership);

    expect($membership->fresh()->status)->toBe(MembershipStatus::Deactivated)
        ->and($worker->tokens()->count())->toBe(0)
        ->and($worker->isMemberOf($this->org))->toBeFalse();

    $admin = memberOf($this->org, SystemRole::Admin);
    actingInAdmin($admin, $this->org);
    $ownerMembership = Membership::query()->where('user_id', $this->owner->id)->sole();
    Livewire::test(ManageMembers::class)->callTableAction('toggleStatus', $ownerMembership);
    expect($ownerMembership->fresh()->status)->toBe(MembershipStatus::Active);
});

it('creates groups with names unique per organization', function (): void {
    actingInAdmin($this->owner, $this->org);
    Livewire::test(ManageGroups::class)->callAction('create', ['name' => 'Office'])->assertHasNoActionErrors();
    Livewire::test(ManageGroups::class)->callAction('create', ['name' => 'Office'])->assertHasActionErrors(['name']);

    $other = makeOrganization(name: 'Other');
    actingInAdmin(ownerOf($other), $other);
    Livewire::test(ManageGroups::class)->callAction('create', ['name' => 'Office'])->assertHasNoActionErrors();
});

it('builds a custom role from capabilities', function (): void {
    actingInAdmin($this->owner, $this->org);
    $perm = Spatie\Permission\Models\Permission::findByName('reports.view', 'web');

    Livewire::test(ManageRoles::class)
        ->callAction('create', ['name' => 'Accountant', 'permission_ids' => [$perm->id]])
        ->assertHasNoActionErrors();

    $role = Role::query()->where('organization_id', $this->org->id)->where('name', 'Accountant')->sole();
    expect($role->permissions->pluck('name')->all())->toBe(['reports.view']);
});

it('forbids team management without members.manage', function (SystemRole $role): void {
    $user = memberOf($this->org, $role);
    actingInAdmin($user, $this->org);

    $this->get("/admin/{$this->org->slug}/members")->assertForbidden();
    $this->get("/admin/{$this->org->slug}/groups")->assertForbidden();
    $this->get("/admin/{$this->org->slug}/roles")->assertForbidden();
})->with([SystemRole::Designer, SystemRole::Reviewer, SystemRole::Viewer]);

it('protects system roles from deletion and the owner role from edits', function (): void {
    actingInAdmin($this->owner, $this->org);
    $ownerRole = Role::query()->where('organization_id', $this->org->id)->where('name', 'owner')->sole();
    $viewer = Role::query()->where('organization_id', $this->org->id)->where('name', 'viewer')->sole();

    expect($this->owner->can('update', $ownerRole))->toBeFalse()
        ->and($this->owner->can('delete', $viewer))->toBeFalse()
        ->and($this->owner->can('update', $viewer))->toBeTrue();
});

it('does not expose another organization\'s records through policies', function (): void {
    $other = makeOrganization(name: 'Other');
    $foreignRole = Role::query()->where('organization_id', $other->id)->where('name', 'viewer')->sole();
    actingInAdmin($this->owner, $this->org);

    expect($this->owner->can('update', $foreignRole))->toBeFalse()
        ->and($this->owner->can('view', Membership::query()->where('organization_id', $other->id)->first()))->toBeFalse();
});

it('revokes all devices of a member', function (): void {
    actingInAdmin($this->owner, $this->org);
    $worker = memberOf($this->org, SystemRole::FieldWorker);
    $device = Device::query()->create(['user_id' => $worker->id, 'device_uuid' => (string) Str::uuid(), 'secret_hash' => 'x']);

    Livewire::test(ManageMembers::class)->callTableAction('revokeDevices', Membership::query()->where('user_id', $worker->id)->sole());

    expect($device->refresh()->revoked_at)->not->toBeNull()->and($device->wipe_requested_at)->not->toBeNull();
});
