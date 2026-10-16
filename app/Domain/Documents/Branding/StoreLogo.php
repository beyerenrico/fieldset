<?php

declare(strict_types=1);

namespace App\Domain\Documents\Branding;

use App\Domain\Identity\Models\Organization;
use finfo;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use RuntimeException;

/**
 * Validates and stores an organization logo (§8.5, §11 upload validation): content sniffing (PNG,
 * JPEG, WebP), size and pixel limits checked before decoding, then a GD re-encode to PNG, which drops
 * metadata and any embedded payload. Stored on the private disk under branding/{org}/.
 */
final class StoreLogo
{
    private const array TYPES = ['image/png', 'image/jpeg', 'image/webp'];

    public function handle(Organization $organization, string $bytes): string
    {
        $maxBytes = (int) config('documents.logo_max_kb', 2048) * 1024;
        if ($bytes === '' || strlen($bytes) > $maxBytes) {
            throw new InvalidArgumentException(__('documents.branding.errors.logo_too_large', ['kb' => intdiv($maxBytes, 1024)]));
        }
        if (! in_array((string) new finfo(FILEINFO_MIME_TYPE)->buffer($bytes), self::TYPES, true)) {
            throw new InvalidArgumentException(__('documents.branding.errors.logo_type'));
        }
        $png = $this->reencode($bytes);
        $path = sprintf('%s/logo-%s.png', self::directory($organization), substr(hash('sha256', $png), 0, 16));
        if (! Storage::disk((string) config('documents.disk', 'local'))->put($path, $png)) {
            throw new RuntimeException("Could not write logo [{$path}].");
        }

        return $path;
    }

    public static function directory(Organization $organization): string
    {
        return 'branding/'.$organization->id;
    }

    /** A logo path belongs to the organization (guards tampered form state). */
    public static function owns(Organization $organization, string $path): bool
    {
        return self::isLogoPathOf($organization->id, $path);
    }

    public static function isLogoPathOf(int $organizationId, string $path): bool
    {
        return preg_match('#^branding/'.$organizationId.'/logo-[0-9a-f]{16}\.png$#', $path) === 1;
    }

    private function reencode(string $bytes): string
    {
        $size = @getimagesizefromstring($bytes);
        $maxSide = (int) config('documents.logo_max_side', 4000);
        if ($size === false || $size[0] > $maxSide || $size[1] > $maxSide || (int) config('documents.logo_max_pixels', 16_000_000) < $size[0] * $size[1]) {
            throw new InvalidArgumentException(__('documents.branding.errors.logo_dimensions', ['max' => $maxSide]));
        }
        $image = @imagecreatefromstring($bytes);
        if ($image === false) {
            throw new InvalidArgumentException(__('documents.branding.errors.logo_type'));
        }
        imagealphablending($image, false);
        imagesavealpha($image, true);
        ob_start();
        imagepng($image, null, 6);
        imagedestroy($image);

        return (string) ob_get_clean();
    }
}
