<?php

declare(strict_types=1);

use App\Domain\Identity\Enums\SystemRole;
use App\Domain\Identity\Models\Membership;
use App\Domain\Privacy\Erasure\AccountScrubber;
use App\Filament\Platform\Resources\Organizations\Pages\ListOrganizations;
use App\Filament\Platform\Resources\Organizations\Pages\OrganizationMembers;
use Carbon\CarbonImmutable;
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

it('shows created and joined dates in the operator\'s locale and timezone', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-17 22:30:00', 'UTC'));
    $acme = makeOrganization(name: 'Acme GmbH');
    $worker = memberOf($acme, SystemRole::FieldWorker);
    Membership::query()->where('user_id', $worker->id)->update(['joined_at' => now()]);
    $operator = platformOperator();
    $operator->forceFill(['locale' => 'de', 'timezone' => 'Europe/Berlin'])->save();
    actingAsPlatformOperator($operator->fresh());

    // 22:30 UTC is already the next day in Berlin (CEST): 18.10.2026 00:30, day before month.
    $this->get('/platform/organizations')->assertOk()->assertSee('18.10.2026 00:30')->assertDontSee('Oct 17, 2026');
    $this->get("/platform/organizations/{$acme->id}/members")->assertOk()->assertSee('18.10.2026 00:30');
});

it('shows an erased account with the translated neutral name', function (): void {
    $acme = makeOrganization(name: 'Acme GmbH');
    $erased = memberOf($acme, SystemRole::FieldWorker);
    $erased->forceFill(['name' => AccountScrubber::ERASED_NAME])->save();
    $operator = platformOperator();
    $operator->forceFill(['locale' => 'de'])->save();
    actingAsPlatformOperator($operator->fresh());

    $this->get("/platform/organizations/{$acme->id}/members")->assertOk()
        ->assertSee(__('privacy.erased_name', [], 'de'))->assertDontSee(AccountScrubber::ERASED_NAME);
});
