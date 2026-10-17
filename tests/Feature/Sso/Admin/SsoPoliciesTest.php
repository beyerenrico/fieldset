<?php

declare(strict_types=1);

use App\Domain\Identity\Enums\Permission;
use App\Domain\Identity\Enums\SystemRole;
use App\Domain\Identity\Models\IdentityProvider;
use App\Domain\Identity\Models\SsoDomain;
use App\Domain\Identity\Tenancy\TenantContext;

/* §8.6 "Users, roles, groups, SSO": Owner and Admin (members.manage), own organization only. Setting up an
   identity provider and its security settings need Owner level too (gate review M7). */

beforeEach(function (): void {
    $this->tenant = app(TenantContext::class);
    $this->org = makeOrganization();
    $this->other = makeOrganization(name: 'Other');
    $this->foreignProvider = $this->tenant->run($this->other, fn () => IdentityProvider::factory()->create());
    $this->foreignDomain = $this->tenant->run($this->other, fn () => SsoDomain::factory()->create());
    $this->tenant->set($this->org);
    $this->provider = IdentityProvider::factory()->create();
    $this->domain = SsoDomain::factory()->create();
});

it('lets exactly the members.manage roles manage SSO', function (SystemRole $role, bool $allowed): void {
    $user = $role === SystemRole::Owner ? ownerOf($this->org) : memberOf($this->org, $role);
    $this->tenant->set($this->org);

    foreach ([IdentityProvider::class => $this->provider, SsoDomain::class => $this->domain] as $class => $record) {
        $ownerOnly = $class === IdentityProvider::class && $role !== SystemRole::Owner; // creating a provider is Owner-level
        expect($user->can('viewAny', $class))->toBe($allowed)
            ->and($user->can('create', $class))->toBe($allowed && ! $ownerOnly)
            ->and($user->can('view', $record))->toBe($allowed)
            ->and($user->can('update', $record))->toBe($allowed)
            ->and($user->can('delete', $record))->toBe($allowed);
    }
})->with([
    'owner' => [SystemRole::Owner, true],
    'admin' => [SystemRole::Admin, true],
    'designer' => [SystemRole::Designer, false],
    'reviewer' => [SystemRole::Reviewer, false],
    'field worker' => [SystemRole::FieldWorker, false],
    'viewer' => [SystemRole::Viewer, false],
]);

it('follows the members.manage permission, not the role name', function (): void {
    $lead = memberOf($this->org, SystemRole::Reviewer);
    $this->tenant->run($this->org, fn () => $lead->givePermissionTo(Permission::MembersManage->value));
    $this->tenant->set($this->org);

    expect($lead->can('update', $this->provider))->toBeTrue()->and($lead->can('delete', $this->domain))->toBeTrue();
});

it('keeps creating a provider and its security settings to Owner level', function (SystemRole $role, bool $allowed): void {
    $user = $role === SystemRole::Owner ? ownerOf($this->org) : memberOf($this->org, $role);
    $this->tenant->set($this->org);

    expect($user->can('create', IdentityProvider::class))->toBe($allowed)
        ->and($user->can('manageSecurity', $this->provider))->toBe($allowed)
        ->and(ownerOf($this->org)->can('manageSecurity', $this->foreignProvider))->toBeFalse();
})->with([
    'owner' => [SystemRole::Owner, true],
    'admin' => [SystemRole::Admin, false],
    'designer' => [SystemRole::Designer, false],
    'viewer' => [SystemRole::Viewer, false],
]);

it('never allows records of another organization', function (): void {
    $owner = ownerOf($this->org);

    expect($owner->can('view', $this->foreignProvider))->toBeFalse()
        ->and($owner->can('update', $this->foreignProvider))->toBeFalse()
        ->and($owner->can('delete', $this->foreignDomain))->toBeFalse()
        ->and(ownerOf($this->other)->can('update', $this->provider))->toBeFalse();
});
