<?php

declare(strict_types=1);

namespace App\Domain\Documents\Branding;

use App\Domain\Identity\Models\Organization;

/**
 * Organization branding (§8.5) as applied to PDFs, emails and the Field App: colors, a bundled
 * OFL font, the logo on the private disk and company details. Stored in `organizations.branding`
 * and `organizations.company_details`; invalid or missing values fall back to defaults.
 */
final readonly class Branding
{
    public const string DEFAULT_PRIMARY = '#1d4ed8';

    public const string DEFAULT_ACCENT = '#b45309';

    public const string DEFAULT_FONT = 'inter';

    /** Bundled OFL-1.1 fonts (resources/fonts/<key>/), key => display name. */
    public const array FONTS = [
        'inter' => 'Inter',
        'source-sans-3' => 'Source Sans 3',
        'noto-sans' => 'Noto Sans',
        'noto-serif' => 'Noto Serif',
    ];

    /** Company detail keys shown in document footers and mails. */
    public const array COMPANY_KEYS = ['legal_name', 'address', 'email', 'phone', 'website', 'tax_id', 'vat_id', 'bank_name', 'iban', 'bic', 'legal_footer'];

    /** @param  array<string, string>  $company */
    public function __construct(
        public string $primaryColor,
        public string $accentColor,
        public string $font,
        public ?string $logoPath,
        public string $companyName,
        public array $company,
    ) {}

    public static function forOrganization(Organization $organization): self
    {
        $branding = $organization->branding;
        $company = array_filter(
            array_intersect_key($organization->company_details, array_flip(self::COMPANY_KEYS)),
            fn (mixed $value): bool => is_string($value) && trim($value) !== '',
        );
        $font = $branding['font'] ?? null;
        $logo = $branding['logo_path'] ?? null;

        return new self(
            self::color($branding['primary_color'] ?? null, self::DEFAULT_PRIMARY),
            self::color($branding['accent_color'] ?? null, self::DEFAULT_ACCENT),
            is_string($font) && array_key_exists($font, self::FONTS) ? $font : self::DEFAULT_FONT,
            is_string($logo) && $logo !== '' ? $logo : null,
            $company['legal_name'] ?? $organization->name,
            $company,
        );
    }

    /** Text color on a primary-colored fill (table headers, mail buttons): white or dark, whichever is readable. */
    public function onPrimary(): string
    {
        return ColorContrast::textOn($this->primaryColor);
    }

    /** The primary color for text on white (headings, links), darkened until it reaches 4.5:1. */
    public function primaryText(): string
    {
        return ColorContrast::readableOn($this->primaryColor);
    }

    /** The accent color for text on white (section titles), darkened until it reaches 4.5:1. */
    public function accentText(): string
    {
        return ColorContrast::readableOn($this->accentColor);
    }

    /** @return array{primary_color: string, accent_color: string, font: string, logo_path: string|null, company_name: string, company: array<string, string>} */
    public function toArray(): array
    {
        return [
            'primary_color' => $this->primaryColor,
            'accent_color' => $this->accentColor,
            'font' => $this->font,
            'logo_path' => $this->logoPath,
            'company_name' => $this->companyName,
            'company' => $this->company,
        ];
    }

    private static function color(mixed $value, string $fallback): string
    {
        return is_string($value) && preg_match('/^#[0-9a-f]{6}$/i', $value) === 1 ? strtolower($value) : $fallback;
    }
}
