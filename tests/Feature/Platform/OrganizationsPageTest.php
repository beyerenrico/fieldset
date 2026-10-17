<?php

declare(strict_types=1);

use App\Domain\Identity\Enums\SystemRole;
use App\Filament\Platform\Resources\Organizations\Pages\ListOrganizations;
use App\Filament\Platform\Resources\Organizations\Pages\OrganizationMembers;
use Livewire\Livewire;

require_once __DIR__.'/Support.php';

it('lists every organization with active members, created date and status', function (): void {
    $acme = makeOrganization(name: 'Acme GmbH');
    memberOf($acme, SystemRole::FieldWorker);
    $archived = makeOrganization(name: 'Old Ltd');
    $archived->delete();
    actingAsPlatformOperator(platformOperator());

    Livewire::test(ListOrganizations::class)
        ->assertCanSeeTableRecords([$acme, $archived])
        ->assertTableColumnStateSet('active_members_count', 2, $acme)
        ->assertTableColumnStateSet('status', 'archived', $archived)
        ->assertTableColumnStateSet('status', 'active', $acme);

    $this->get('/platform/organizations')->assertOk()->assertSee('Acme GmbH')->assertSee('Old Ltd');
});

it('shows the members of one organization', function (): void {
    $acme = makeOrganization(name: 'Acme GmbH');
    $worker = memberOf($acme, SystemRole::FieldWorker);
    $other = makeOrganization(name: 'Other AG');
    $stranger = memberOf($other, SystemRole::Admin);
    actingAsPlatformOperator(platformOperator());

    $this->get("/platform/organizations/{$acme->id}/members")->assertOk()
        ->assertSee($worker->email)->assertDontSee($stranger->email);

    Livewire::test(OrganizationMembers::class, ['record' => $acme->id])
        ->assertCanSeeTableRecords($acme->memberships()->get())
        ->assertCanNotSeeTableRecords($other->memberships()->get());
});

it('keeps organization members out of the members page', function (): void {
    $acme = makeOrganization();

    $this->actingAs(ownerOf($acme))->get("/platform/organizations/{$acme->id}/members")->assertForbidden();
});
