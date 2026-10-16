<?php

declare(strict_types=1);

use App\Domain\Documents\Branding\Branding;
use App\Domain\Documents\Branding\ColorContrast;
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

it('computes WCAG contrast ratios', function (): void {
    expect(round(ColorContrast::ratio('#ffffff', '#000000'), 2))->toBe(21.0)
        ->and(round(ColorContrast::ratio('#777777', '#ffffff'), 2))->toBe(4.48)
        ->and(ColorContrast::ratio('#1d4ed8', '#ffffff'))->toBeGreaterThan(ColorContrast::AA);
});

it('picks white or dark text on the primary color and darkens text colors until 4.5:1 on white', function (string $color, string $onPrimary, bool $changes): void {
    $branding = Branding::forOrganization(new Organization(['name' => 'Acme', 'branding' => ['primary_color' => $color, 'accent_color' => $color], 'company_details' => []]));

    expect($branding->onPrimary())->toBe($onPrimary)
        ->and(ColorContrast::ratio($branding->onPrimary(), $color))->toBe(max(ColorContrast::ratio('#ffffff', $color), ColorContrast::ratio('#111827', $color)))
        ->and(ColorContrast::ratio($branding->primaryText(), '#ffffff'))->toBeGreaterThanOrEqual(ColorContrast::AA)
        ->and(ColorContrast::ratio($branding->accentText(), '#ffffff'))->toBeGreaterThanOrEqual(ColorContrast::AA)
        ->and($branding->primaryText() !== $color)->toBe($changes)
        ->and($branding->accentText())->toBe($branding->primaryText());
})->with([
    'yellow (facc15)' => ['#facc15', '#111827', true],
    'default blue' => ['#1d4ed8', '#ffffff', false],
    'light cyan' => ['#67e8f9', '#111827', true],
    'dark teal' => ['#0f766e', '#ffffff', false],
    'mid gray (4.48:1 on white)' => ['#777777', '#ffffff', true],
]);
