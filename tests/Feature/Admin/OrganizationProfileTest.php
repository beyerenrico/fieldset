<?php

declare(strict_types=1);

use App\Filament\Admin\Pages\ManageBranding;
use App\Filament\Admin\Pages\Tenancy\EditOrganizationProfile;
use Livewire\Livewire;
use Tests\Feature\Documents\DocumentFixtures;

/* Regression: saving the organization settings wiped company details set on the Branding page. */

beforeEach(function (): void {
    DocumentFixtures::fakeDisk();
    $this->org = makeOrganization();
    $this->owner = ownerOf($this->org);
    actingInAdmin($this->owner, $this->org);
});

it('keeps company details from the Branding page when the organization settings are saved', function (): void {
    Livewire::test(ManageBranding::class)
        ->fillForm(['company' => [
            'legal_name' => 'Acme Aufzüge GmbH', 'email' => 'info@acme.example', 'phone' => '+49 30 1234',
            'address' => "Weg 1\n10115 Berlin", 'iban' => 'DE02120300000000202051', 'legal_footer' => 'AG Berlin HRB 1',
        ]])
        ->call('save')->assertHasNoFormErrors();
    $before = $this->org->refresh()->company_details;

    Livewire::test(EditOrganizationProfile::class)
        ->assertOk()
        ->fillForm(['name' => 'Acme Service GmbH', 'locale' => 'en', 'timezone' => 'Europe/Vienna'])
        ->call('save')->assertHasNoFormErrors();

    $org = $this->org->refresh();
    expect($org->name)->toBe('Acme Service GmbH')
        ->and($org->locale)->toBe('en')
        ->and($org->timezone)->toBe('Europe/Vienna')
        ->and($org->company_details)->toBe($before)
        ->and($org->company_details['legal_name'])->toBe('Acme Aufzüge GmbH')
        ->and($org->company_details['email'])->toBe('info@acme.example');
});

it('never writes branding or company details from the settings page, even when posted', function (): void {
    $this->org->forceFill(['company_details' => ['legal_name' => 'Acme GmbH'], 'branding' => ['primary_color' => '#0f766e']])->save();

    Livewire::test(EditOrganizationProfile::class)
        ->assertSee(__('identity.organization.company_details_hint'))
        ->set('data.company_details', ['legal_name' => 'Hijacked'])
        ->set('data.branding', ['primary_color' => '#000000'])
        ->call('save')->assertHasNoFormErrors();

    $org = $this->org->refresh();
    expect($org->company_details)->toBe(['legal_name' => 'Acme GmbH'])
        ->and($org->branding['primary_color'])->toBe('#0f766e');
});
