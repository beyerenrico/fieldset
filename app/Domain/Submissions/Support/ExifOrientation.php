<?php

declare(strict_types=1);

namespace App\Domain\Submissions\Support;

use GdImage;

/**
 * EXIF orientation of camera photos (M3 review #12): phones store pixels as shot and record the rotation
 * in the Orientation tag. Re-encoding with GD drops EXIF, so the rotation is applied to the pixels first
 * (uploads and print). Without ext-exif (present in the app image) photos keep their stored orientation.
 */
final class ExifOrientation
{
    /** 1–8 per EXIF; 1 (as stored) when unknown, not a JPEG or ext-exif is missing. */
    public static function read(string $bytes): int
    {
        if (! function_exists('exif_read_data') || ! str_starts_with($bytes, "\xFF\xD8")) {
            return 1;
        }
        $stream = fopen('php://memory', 'r+b');
        if ($stream === false) {
            return 1;
        }
        fwrite($stream, $bytes);
        rewind($stream);
        $exif = @exif_read_data($stream);
        fclose($stream);
        $orientation = is_array($exif) ? ($exif['Orientation'] ?? 1) : 1;

        return is_int($orientation) && $orientation >= 1 && $orientation <= 8 ? $orientation : 1;
    }

    /** The image as it should be displayed; may return a new GdImage (the caller frees both). */
    public static function apply(GdImage $image, int $orientation): GdImage
    {
        $rotated = match ($orientation) {
            3 => imagerotate($image, 180, 0),
            5, 6 => imagerotate($image, -90, 0),
            7, 8 => imagerotate($image, 90, 0),
            default => $image,
        };
        $result = $rotated instanceof GdImage ? $rotated : $image;
        if (in_array($orientation, [2, 5, 7], true)) {
            imageflip($result, IMG_FLIP_HORIZONTAL);
        } elseif ($orientation === 4) {
            imageflip($result, IMG_FLIP_VERTICAL);
        }

        return $result;
    }
}
