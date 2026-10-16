<?php

declare(strict_types=1);

use App\Domain\Audit\Models\Activity;
use App\Domain\Identity\Actions\AddMember;
use App\Domain\Identity\Actions\ChangeMemberRoles;
use App\Domain\Identity\Actions\InviteMember;
use App\Domain\Identity\Actions\SaveRole;
use App\Domain\Identity\Actions\SetMembershipStatus;
use App\Domain\Identity\Enums\MembershipStatus;
use App\Domain\Identity\Enums\Permission;
use App\Domain\Identity\Enums\SystemRole;
use App\Domain\Identity\Models\Membership;
use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;
use App\Filament\Admin\Resources\Members\Pages\ManageMembers;
use App\Filament\Admin\Support\OrganizationOptions;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->org = makeOrganization();
    $this->owner = ownerOf($this->org);
    $this->admin = memberOf($this->org, SystemRole::Admin);
});

function membershipOf(User $user): Membership
{
    return Membership::query()->where('user_id', $user->id)->sole();
}

it('does not offer the owner role to admins', function (): void {
    actingInAdmin($this->admin, $this->org);

    expect(array_keys(OrganizationOptions::roles()))->not->toContain('owner')->toContain('admin', 'viewer');
});

it('stops an admin from making themselves owner', function (): void {
    expect(fn () => app(ChangeMemberRoles::class)->handle($this->admin, membershipOf($this->admin), ['owner']))
        ->toThrow(ValidationException::class);
    expect($this->admin->hasPermissionIn($this->org, Permission::OrganizationDelete))->toBeFalse();
});

it('stops an admin from granting or removing the owner role on others', function (): void {
    $viewer = memberOf($this->org, SystemRole::Viewer);

    expect(fn () => app(ChangeMemberRoles::class)->handle($this->admin, membershipOf($viewer), ['owner']))->toThrow(ValidationException::class)
        ->and(fn () => app(ChangeMemberRoles::class)->handle($this->admin, membershipOf($this->owner), ['viewer']))->toThrow(ValidationException::class);
    expect($this->owner->hasPermissionIn($this->org, Permission::OrganizationDelete))->toBeTrue();
});

it('stops an admin from inviting an owner', function (): void {
    Notification::fake();

    expect(fn () => app(InviteMember::class)->handle($this->admin, $this->org, 'evil@example.com', 'Evil', ['owner']))
        ->toThrow(ValidationException::class);
    expect(User::query()->where('email', 'evil@example.com')->exists())->toBeFalse();
});

it('stops an admin from adding organization.delete to a role', function (): void {
    $adminRole = Role::query()->where('organization_id', $this->org->id)->where('name', 'admin')->sole();
    $ids = Spatie\Permission\Models\Permission::query()->pluck('id')->all();

    expect(fn () => app(SaveRole::class)->handle($this->admin, $this->org, $adminRole, null, $ids))->toThrow(ValidationException::class)
        ->and(fn () => app(SaveRole::class)->handle($this->admin, $this->org, null, 'Sneaky', $ids))->toThrow(ValidationException::class);
    expect(Role::query()->where('name', 'Sneaky')->exists())->toBeFalse();
});

it('lets an owner transfer ownership but never leave the organization ownerless', function (): void {
    $successor = memberOf($this->org, SystemRole::Admin);
    $otherOwner = memberOf($this->org, SystemRole::Owner);

    app(ChangeMemberRoles::class)->handle($this->owner, membershipOf($successor), ['owner']);
    expect($successor->hasPermissionIn($this->org, Permission::OrganizationDelete))->toBeTrue();

    app(ChangeMemberRoles::class)->handle($successor, membershipOf($otherOwner), ['admin']);
    app(ChangeMemberRoles::class)->handle($successor, membershipOf($this->owner), ['admin']);
    expect(fn () => app(ChangeMemberRoles::class)->handle($otherOwner, membershipOf($successor), ['admin']))
        ->toThrow(ValidationException::class);
});

it('blocks editing your own roles', function (): void {
    expect(fn () => app(ChangeMemberRoles::class)->handle($this->owner, membershipOf($this->owner), ['viewer']))
        ->toThrow(ValidationException::class);
});

it('only lets owners deactivate owners, and never the last one', function (): void {
    $second = memberOf($this->org, SystemRole::Owner);

    expect(fn () => app(SetMembershipStatus::class)->handle(membershipOf($second), MembershipStatus::Deactivated, $this->admin))
        ->toThrow(ValidationException::class);

    app(SetMembershipStatus::class)->handle(membershipOf($second), MembershipStatus::Deactivated, $this->owner);
    expect(fn () => app(SetMembershipStatus::class)->handle(membershipOf($this->owner), MembershipStatus::Deactivated))
        ->toThrow(ValidationException::class);
});

it('shows the last-owner refusal in the panel', function (): void {
    $second = memberOf($this->org, SystemRole::Owner);
    actingInAdmin($second, $this->org);

    Livewire::test(ManageMembers::class)->callTableAction('toggleStatus', membershipOf($this->owner));
    actingInAdmin($this->owner, $this->org);
    Livewire::test(ManageMembers::class)
        ->callTableAction('toggleStatus', membershipOf($second))
        ->assertNotified(__('identity.member.last_owner'));

    expect(membershipOf($second)->status)->toBe(MembershipStatus::Active);
});

it('revokes only the tokens of the organization that deactivates the member', function (): void {
    $other = makeOrganization(name: 'Other');
    $shared = memberOf($this->org, SystemRole::FieldWorker);
    app(AddMember::class)->handle($other, $shared, [SystemRole::FieldWorker]);
    $shared->createToken('a')->accessToken->forceFill(['organization_id' => $this->org->id])->save();
    $shared->createToken('b')->accessToken->forceFill(['organization_id' => $other->id])->save();

    $membership = Membership::query()->where('user_id', $shared->id)->where('organization_id', $this->org->id)->sole();
    app(SetMembershipStatus::class)->handle($membership, MembershipStatus::Deactivated, $this->owner);

    expect($shared->tokens()->pluck('name')->all())->toBe(['b']);
});

it('audits role and permission changes once each with before and after', function (): void {
    $viewer = memberOf($this->org, SystemRole::Viewer);
    Activity::query()->delete();

    app(ChangeMemberRoles::class)->handle($this->owner, membershipOf($viewer), ['reviewer']);
    $role = app(SaveRole::class)->handle($this->owner, $this->org, null, 'Accountant', [Spatie\Permission\Models\Permission::findByName('reports.view')->id]);
    app(SaveRole::class)->handle($this->owner, $this->org, $role, null, []);

    $events = Activity::query()->orderBy('id')->get(['event', 'properties']);
    expect($events->pluck('event')->all())->toBe(['member.roles_changed', 'role.created', 'role.updated'])
        ->and($events[0]->properties['before'])->toBe(['viewer'])
        ->and($events[0]->properties['after'])->toBe(['reviewer'])
        ->and($events[2]->properties['after']['permissions'])->toBe([]);
});
