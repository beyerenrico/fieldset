<?php

declare(strict_types=1);

namespace App\Domain\Documents\Branding;

use App\Domain\Identity\Models\Organization;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;

/**
 * Saves colors, font, logo and company details (§8.5). Invalid values are refused, company details
 * outside Branding::COMPANY_KEYS are kept untouched, and a replaced logo file is deleted. The
 * Organization model's activity log writes the one audit entry (settings change, §8.11).
 */
final class UpdateBranding
{
    public const int COMPANY_VALUE_MAX = 500;

    /** @param  array<string, mixed>  $data  primary_color, accent_color, font, logo_path, company[] */
    public function handle(Organization $organization, array $data): Organization
    {
        $branding = $organization->branding;
        $previousLogo = is_string($branding['logo_path'] ?? null) ? $branding['logo_path'] : null;
        $logo = is_string($data['logo_path'] ?? null) && $data['logo_path'] !== '' ? $data['logo_path'] : null;
        if ($logo !== null && $logo !== $previousLogo && ! StoreLogo::owns($organization, $logo)) {
            throw new InvalidArgumentException(__('documents.branding.errors.logo_type'));
        }

        $branding = [
            ...$branding,
            'primary_color' => $this->color($data['primary_color'] ?? null, Branding::DEFAULT_PRIMARY),
            'accent_color' => $this->color($data['accent_color'] ?? null, Branding::DEFAULT_ACCENT),
            'font' => is_string($data['font'] ?? null) && array_key_exists($data['font'], Branding::FONTS) ? $data['font'] : Branding::DEFAULT_FONT,
            'logo_path' => $logo,
        ];
        $organization->forceFill(['branding' => $branding, 'company_details' => $this->company($organization, $data['company'] ?? [])])->save();

        if ($previousLogo !== null && $previousLogo !== $logo && StoreLogo::owns($organization, $previousLogo)) {
            Storage::disk((string) config('documents.disk', 'local'))->delete($previousLogo);
        }

        return $organization;
    }

    /** @return array<string, mixed> */
    private function company(Organization $organization, mixed $input): array
    {
        $details = array_diff_key($organization->company_details, array_flip(Branding::COMPANY_KEYS));
        foreach (Branding::COMPANY_KEYS as $key) {
            $value = is_array($input) && is_string($input[$key] ?? null) ? trim($input[$key]) : '';
            if ($value !== '') {
                $details[$key] = mb_substr($value, 0, self::COMPANY_VALUE_MAX);
            }
        }

        return $details;
    }

    private function color(mixed $value, string $default): string
    {
        if ($value === null || $value === '') {
            return $default;
        }
        if (! is_string($value) || preg_match('/^#[0-9a-fA-F]{6}$/', $value) !== 1) {
            throw new InvalidArgumentException(__('documents.branding.errors.color'));
        }

        return strtolower($value);
    }
}
