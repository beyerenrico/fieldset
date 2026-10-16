<?php

declare(strict_types=1);

namespace App\Domain\Documents\Rendering;

use GdImage;

/**
 * Prepares images for print (§8.5): scales the long edge down to at most `$maxEdge` pixels and
 * re-encodes (PNG keeps transparency for signatures and logos, everything else becomes JPEG), then
 * returns a data: URI. Header dimensions are checked before GD allocates pixels (decompression bombs).
 */
final class ImageDownscaler
{
    public const int MAX_SOURCE_PIXELS = 40_000_000;

    private const int JPEG_QUALITY = 82;

    public function dataUri(string $bytes, int $maxEdge): ?string
    {
        $size = @getimagesizefromstring($bytes);
        if ($size === false || $size[0] < 1 || $size[1] < 1 || $size[0] * $size[1] > self::MAX_SOURCE_PIXELS) {
            return null;
        }
        $image = @imagecreatefromstring($bytes);
        if ($image === false) {
            return null;
        }

        $png = $size['mime'] === 'image/png';
        $scaled = $this->scale($image, $size[0], $size[1], $maxEdge, $png);
        $encoded = $this->encode($scaled, $png);
        imagedestroy($scaled);
        if ($scaled !== $image) {
            imagedestroy($image);
        }

        return 'data:image/'.($png ? 'png' : 'jpeg').';base64,'.base64_encode($encoded);
    }

    /**
     * @param  positive-int  $width
     * @param  positive-int  $height
     * @return array{0: positive-int, 1: positive-int} target size with the long edge capped at $maxEdge
     */
    public static function targetSize(int $width, int $height, int $maxEdge): array
    {
        $long = max($width, $height);
        if ($long <= $maxEdge) {
            return [$width, $height];
        }
        $ratio = $maxEdge / $long;

        return [max(1, (int) round($width * $ratio)), max(1, (int) round($height * $ratio))];
    }

    /**
     * @param  positive-int  $width
     * @param  positive-int  $height
     */
    private function scale(GdImage $image, int $width, int $height, int $maxEdge, bool $png): GdImage
    {
        [$w, $h] = self::targetSize($width, $height, $maxEdge);
        if ($w === $width && $h === $height) {
            return $image;
        }
        $target = imagecreatetruecolor($w, $h);
        if ($png) {
            imagealphablending($target, false);
            imagesavealpha($target, true);
            imagefill($target, 0, 0, (int) imagecolorallocatealpha($target, 0, 0, 0, 127));
        }
        imagecopyresampled($target, $image, 0, 0, 0, 0, $w, $h, $width, $height);

        return $target;
    }

    private function encode(GdImage $image, bool $png): string
    {
        ob_start();
        if ($png) {
            imagesavealpha($image, true);
            imagepng($image, null, 6);
        } else {
            imageinterlace($image, false);
            imagejpeg($image, null, self::JPEG_QUALITY);
        }

        return (string) ob_get_clean();
    }
}
