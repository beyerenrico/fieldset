<?php

declare(strict_types=1);

use App\Domain\Audit\Models\Activity;
use App\Domain\Documents\Branding\Branding;
use App\Domain\Documents\Branding\StoreLogo;
use App\Domain\Documents\Branding\UpdateBranding;
use App\Domain\Documents\Designs\DesignSettings;
use App\Domain\Documents\Rendering\PreviewDocument;
use App\Domain\Identity\Enums\SystemRole;
use App\Filament\Admin\Pages\ManageBranding;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Feature\Documents\DocumentFixtures;

beforeEach(function (): void {
    DocumentFixtures::fakeDisk();
    $this->org = makeOrganization();
    $this->owner = ownerOf($this->org);
    actingInAdmin($this->owner, $this->org);
});

function docJpegWithExif(int $w = 640, int $h = 480): string
{
    $image = imagecreatetruecolor($w, $h);
    ob_start();
    imagejpeg($image);
    $jpeg = (string) ob_get_clean();

    // Splice an APP1 "Exif" segment after SOI: re-encoding must drop it.
    return substr($jpeg, 0, 2)."\xFF\xE1\x00\x10Exif\x00\x00SECRETGPS".substr($jpeg, 2);
}

it('saves colors, font and company details (one audit entry)', function (): void {
    Livewire::test(ManageBranding::class)
        ->assertOk()
        ->fillForm([
            'primary_color' => '#0F766E', 'accent_color' => '#9333ea', 'font' => 'noto-serif',
            'company' => ['legal_name' => 'Acme Aufzüge GmbH', 'iban' => 'DE02120300000000202051', 'legal_footer' => 'AG Berlin HRB 1'],
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $branding = Branding::forOrganization($this->org->refresh());
    expect($branding->primaryColor)->toBe('#0f766e')
        ->and($branding->accentColor)->toBe('#9333ea')
        ->and($branding->font)->toBe('noto-serif')
        ->and($branding->companyName)->toBe('Acme Aufzüge GmbH')
        ->and($branding->company['legal_footer'])->toBe('AG Berlin HRB 1')
        ->and(Activity::query()->where('subject_type', $this->org->getMorphClass())->where('event', 'updated')->count())->toBe(1);
});

it('uploads a logo: sniffed, re-encoded to PNG without metadata, stored privately per organization', function (): void {
    Livewire::test(ManageBranding::class)
        ->fillForm(['logo_path' => UploadedFile::fake()->createWithContent('logo.jpg', docJpegWithExif())])
        ->call('save')
        ->assertHasNoFormErrors();

    $path = Branding::forOrganization($this->org->refresh())->logoPath;
    $stored = (string) Storage::disk(DocumentFixtures::DISK)->get((string) $path);
    expect($path)->toMatch('#^branding/'.$this->org->id.'/logo-[0-9a-f]{16}\.png$#')
        ->and(getimagesizefromstring($stored)['mime'])->toBe('image/png')
        ->and($stored)->not->toContain('SECRETGPS');
    Livewire::test(ManageBranding::class)->assertOk()->assertSet('data.primary_color', Branding::DEFAULT_PRIMARY);
});

it('refuses non-images, oversized logos and tampered logo paths', function (): void {
    $store = app(StoreLogo::class);

    expect(fn () => $store->handle($this->org, '%PDF-1.4 not an image'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $store->handle($this->org, str_repeat('x', 3 * 1024 * 1024)))->toThrow(InvalidArgumentException::class);
    config(['documents.logo_max_side' => 100]);
    expect(fn () => $store->handle($this->org, docJpegWithExif(200, 50)))->toThrow(InvalidArgumentException::class)
        ->and(fn () => app(UpdateBranding::class)->handle($this->org, ['logo_path' => 'attachments/9/x/secret.png']))->toThrow(InvalidArgumentException::class)
        ->and(fn () => app(UpdateBranding::class)->handle($this->org, ['primary_color' => 'red; background:url(x)']))->toThrow(InvalidArgumentException::class);
});

it('deletes the previous logo file when the logo is replaced or removed', function (): void {
    $old = app(StoreLogo::class)->handle($this->org, docJpegWithExif(64, 64));
    app(UpdateBranding::class)->handle($this->org, ['logo_path' => $old]);

    app(UpdateBranding::class)->handle($this->org->refresh(), ['logo_path' => null]);

    expect(Storage::disk(DocumentFixtures::DISK)->exists($old))->toBeFalse()
        ->and(Branding::forOrganization($this->org->refresh())->logoPath)->toBeNull();
});

it('applies logo, colors and font to rendered documents; a foreign logo path is never inlined', function (): void {
    DocumentFixtures::fakeGotenberg();
    $logo = app(StoreLogo::class)->handle($this->org, docJpegWithExif(64, 64));
    app(UpdateBranding::class)->handle($this->org, ['logo_path' => $logo, 'primary_color' => '#0f766e', 'font' => 'source-sans-3']);

    app(PreviewDocument::class)->forSettings(DesignSettings::defaults(), $this->org->refresh(), $this->owner);
    $parts = DocumentFixtures::sentParts();
    expect($parts['header.html'])->toContain('<img src="data:image/png;base64,')
        ->and($parts['index.html'])->toContain('--primary: #0f766e')->toContain("font-family: 'Source Sans 3'");

    $foreign = 'branding/999999/logo-0123456789abcdef.png';
    Storage::disk(DocumentFixtures::DISK)->put($foreign, (string) Storage::disk(DocumentFixtures::DISK)->get($logo));
    $this->org->forceFill(['branding' => [...$this->org->branding, 'logo_path' => $foreign]])->save();
    app(PreviewDocument::class)->forSettings(DesignSettings::defaults(), $this->org->refresh(), $this->owner);
    expect(DocumentFixtures::sentParts()['header.html'])->not->toContain('<img');
});

it('is only available with the organization settings capability', function (): void {
    actingInAdmin(memberOf($this->org, SystemRole::Designer), $this->org);

    expect(ManageBranding::canAccess())->toBeFalse();
    Livewire::test(ManageBranding::class)->assertForbidden();
});
