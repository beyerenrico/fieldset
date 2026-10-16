<?php

declare(strict_types=1);

use App\Domain\Identity\Models\Group;
use App\Domain\Identity\Models\Organization;
use App\Domain\Identity\Tenancy\TenantContext;
use App\Domain\Identity\Tenancy\TenantNotSetException;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function (): void {
    $this->tenant = app(TenantContext::class);
    $this->orgA = Organization::factory()->create();
    $this->orgB = Organization::factory()->create();
});

it('throws when a tenant-owned model is queried without a tenant', function (): void {
    Group::query()->get();
})->throws(TenantNotSetException::class);

it('throws when a tenant-owned model is created without a tenant', function (): void {
    Group::query()->create(['name' => 'Crew']);
})->throws(TenantNotSetException::class);

it('fills organization_id from the tenant and scopes reads to it', function (): void {
    $this->tenant->run($this->orgA, fn () => Group::query()->create(['name' => 'A crew']));
    $this->tenant->run($this->orgB, fn () => Group::query()->create(['name' => 'B crew']));

    $this->tenant->set($this->orgA);

    expect(Group::query()->pluck('name')->all())->toBe(['A crew'])
        ->and(Group::query()->first()->organization_id)->toBe($this->orgA->id);
});

it('refuses to create a record for another organization', function (): void {
    $this->tenant->set($this->orgA);

    $group = new Group(['name' => 'Sneaky']);
    $group->organization_id = $this->orgB->id;
    $group->save();
})->throws(TenantNotSetException::class);

it('refuses to move a record to another organization', function (): void {
    $this->tenant->set($this->orgA);
    $group = Group::query()->create(['name' => 'Crew']);

    $group->organization_id = $this->orgB->id;
    $group->save();
})->throws(TenantNotSetException::class);

it('lets platform code opt out explicitly', function (): void {
    $this->tenant->run($this->orgA, fn () => Group::query()->create(['name' => 'A']));
    $this->tenant->run($this->orgB, fn () => Group::query()->create(['name' => 'B']));

    $all = $this->tenant->withoutTenant(fn () => Group::query()->count());

    expect($all)->toBe(2)
        ->and(Group::query()->withoutTenant()->count())->toBe(2)
        ->and($this->tenant->isBypassed())->toBeFalse();
});

it('restores the previous tenant after run()', function (): void {
    $this->tenant->set($this->orgA);

    $inner = $this->tenant->run($this->orgB, fn () => $this->tenant->id());

    expect($inner)->toBe($this->orgB->id)->and($this->tenant->id())->toBe($this->orgA->id);
});

it('keeps the spatie team id in sync with the tenant', function (): void {
    $registrar = app(PermissionRegistrar::class);

    $this->tenant->set($this->orgA);
    expect($registrar->getPermissionsTeamId())->toBe($this->orgA->id);

    $this->tenant->clear();
    expect($registrar->getPermissionsTeamId())->toBeNull()
        ->and($this->tenant->check())->toBeFalse();
});
