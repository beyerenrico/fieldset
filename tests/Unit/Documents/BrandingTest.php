<?php

declare(strict_types=1);

use App\Domain\Documents\Branding\Branding;
use App\Domain\Identity\Models\Organization;

it('falls back to defaults for missing or invalid branding values', function (): void {
    $org = new Organization(['name' => 'Acme', 'branding' => ['primary_color' => 'red', 'font' => 'comic-sans'], 'company_details' => []]);

    $branding = Branding::forOrganization($org);

    expect($branding->primaryColor)->toBe(Branding::DEFAULT_PRIMARY)
        ->and($branding->accentColor)->toBe(Branding::DEFAULT_ACCENT)
        ->and($branding->font)->toBe(Branding::DEFAULT_FONT)
        ->and($branding->logoPath)->toBeNull()
        ->and($branding->companyName)->toBe('Acme');
});

it('reads colors, font, logo and company details', function (): void {
    $org = new Organization(['name' => 'Acme', 'branding' => [
        'primary_color' => '#0F766E', 'accent_color' => '#b45309', 'font' => 'noto-serif', 'logo_path' => 'branding/1/logo.png',
    ], 'company_details' => ['legal_name' => 'Acme GmbH', 'address' => "Weg 1\n10115 Berlin", 'tax_id' => 'DE123', 'iban' => 'DE02', 'unknown' => 'x']]);

    $branding = Branding::forOrganization($org);

    expect($branding->primaryColor)->toBe('#0f766e')
        ->and($branding->accentColor)->toBe('#b45309')
        ->and($branding->font)->toBe('noto-serif')
        ->and($branding->logoPath)->toBe('branding/1/logo.png')
        ->and($branding->companyName)->toBe('Acme GmbH')
        ->and($branding->company)->toBe(['legal_name' => 'Acme GmbH', 'address' => "Weg 1\n10115 Berlin", 'tax_id' => 'DE123', 'iban' => 'DE02']);
});
