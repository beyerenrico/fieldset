<?php

declare(strict_types=1);

use App\Domain\Identity\Enums\Permission;
use App\Domain\Identity\Enums\SystemRole;
use App\Domain\Identity\Models\Organization;
use App\Domain\Identity\Models\User;
use App\Filament\Admin\Pages\Tenancy\RegisterOrganization;
use Filament\Facades\Filament;
use Livewire\Livewire;

it('shows the login page', function (): void {
    $this->get('/admin/login')->assertOk();
});

it('lets an owner open the tenant dashboard', function (): void {
    $org = makeOrganization();

    $this->actingAs(ownerOf($org))->get("/admin/{$org->slug}")->assertOk();
});

dataset('panel roles', [
    'owner' => [SystemRole::Owner, true],
    'admin' => [SystemRole::Admin, true],
    'designer' => [SystemRole::Designer, true],
    'reviewer' => [SystemRole::Reviewer, true],
    'viewer' => [SystemRole::Viewer, true],
    'field worker' => [SystemRole::FieldWorker, false],
]);

it('keeps field workers out of /admin', function (SystemRole $role, bool $allowed): void {
    $org = makeOrganization();
    $user = memberOf($org, $role);

    $response = $this->actingAs($user)->get("/admin/{$org->slug}");

    $allowed ? $response->assertOk() : $response->assertForbidden();
})->with('panel roles');

it('refuses tenants the user is not a member of', function (): void {
    $orgA = makeOrganization(name: 'A');
    $orgB = makeOrganization(name: 'B');

    $this->actingAs(ownerOf($orgA))->get("/admin/{$orgB->slug}")->assertNotFound();
});

it('refuses deactivated users', function (): void {
    $org = makeOrganization();
    $user = User::factory()->deactivated()->memberOf($org, SystemRole::Admin)->create();

    $this->actingAs($user)->get("/admin/{$org->slug}")->assertForbidden();
});

it('registers an organization through the tenant registration page', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user);
    Filament::setCurrentPanel('admin');

    Livewire::test(RegisterOrganization::class)
        ->fillForm(['name' => 'Neue Firma', 'locale' => 'de', 'timezone' => 'Europe/Berlin'])
        ->call('register')
        ->assertHasNoFormErrors();

    $org = Organization::query()->where('name', 'Neue Firma')->firstOrFail();
    expect($user->isMemberOf($org))->toBeTrue()
        ->and($user->hasPermissionIn($org, Permission::OrganizationDelete))->toBeTrue();
});
