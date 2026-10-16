<?php

declare(strict_types=1);

namespace App\Domain\Documents\Rendering;

use App\Domain\Documents\Branding\Branding;
use App\Domain\Documents\Branding\StoreLogo;
use Illuminate\Support\Facades\Storage;

/**
 * Turns photos, signatures and the logo into data: URIs: the renderer has no network access, so
 * every asset travels inside the HTML (§8.5). Photos are downscaled for print.
 */
final readonly class AssetInliner
{
    private const int LOGO_MAX_EDGE = 800;

    private const int SIGNATURE_MAX_EDGE = 1200;

    public function __construct(private ImageDownscaler $images) {}

    public function attachment(RenderInput $input, mixed $uuid): ?string
    {
        $asset = $input->asset($uuid);
        $bytes = $asset?->bytes();
        if (! $asset instanceof RenderAsset || $bytes === null || ! str_starts_with($asset->mime, 'image/')) {
            return null;
        }
        $edge = $asset->kind === 'signature' ? self::SIGNATURE_MAX_EDGE : (int) config('documents.photo_max_edge', 1600);

        return $this->images->dataUri($bytes, $edge);
    }

    /** Only the organization's own stored logo is inlined (defense in depth against a foreign path in branding). */
    public function logo(Branding $branding, int $organizationId): ?string
    {
        if ($branding->logoPath === null || ! StoreLogo::isLogoPathOf($organizationId, $branding->logoPath)) {
            return null;
        }
        $disk = Storage::disk((string) config('documents.disk', 'local'));
        $bytes = $disk->exists($branding->logoPath) ? $disk->get($branding->logoPath) : null;

        return $bytes === null ? null : $this->images->dataUri($bytes, self::LOGO_MAX_EDGE);
    }

    /** Spec `image` elements carry their own data: URI (spec §3.2); anything else is not rendered. */
    public static function inlineImage(mixed $src): ?string
    {
        return is_string($src) && preg_match('#^data:image/(png|jpeg|webp);base64,[A-Za-z0-9+/=\s]+$#', $src) === 1 ? $src : null;
    }
}
