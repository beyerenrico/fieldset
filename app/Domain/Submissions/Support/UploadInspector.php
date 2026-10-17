<?php

declare(strict_types=1);

namespace App\Domain\Submissions\Support;

use App\Http\Api\ApiException;
use finfo;
use GdImage;
use Illuminate\Support\Facades\Log;

/**
 * Upload validation (BUILD_PROMPT §11): content sniffing (never the client header), a per-kind MIME
 * allow-list (the stored extension comes from the sniffed type, never from the client's file name),
 * size limits, dimension limits, an optional malware scan (UploadScanner, ClamAV, ADR 0040) and image
 * re-encoding with GD (drops EXIF and any embedded payload). The EXIF orientation is applied to the
 * pixels first, and photos above the print limit (submissions.image_store_max_pixels, 25 MP) are
 * scaled down, so every stored image can be rendered.
 */
final readonly class UploadInspector
{
    public const int MAX_BYTES = 25 * 1024 * 1024;

    private const array IMAGE_TYPES = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];

    private const array FILE_TYPES = [
        'application/pdf' => 'pdf', 'text/plain' => 'txt', 'text/csv' => 'csv',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
        'application/vnd.oasis.opendocument.text' => 'odt', 'application/vnd.oasis.opendocument.spreadsheet' => 'ods',
        'application/zip' => 'zip',
    ];

    public function __construct(private UploadScanner $scanner = new NullUploadScanner) {}

    /**
     * @param  list<string>  $accept  element `validation.accept` (MIME types or type/* wildcards)
     * @return array{bytes: string, mime: string, extension: string}
     */
    public function inspect(string $bytes, string $kind, int $maxBytes, array $accept = []): array
    {
        if ($bytes === '' || strlen($bytes) > min($maxBytes, self::MAX_BYTES)) {
            throw new ApiException('file_too_large', __('submissions.errors.file_too_large'), 422);
        }

        $mime = (string) new finfo(FILEINFO_MIME_TYPE)->buffer($bytes);
        $allowed = match ($kind) {
            'photo' => self::IMAGE_TYPES,
            'signature' => ['image/png' => 'png'],
            default => self::IMAGE_TYPES + self::FILE_TYPES,
        };

        if (! isset($allowed[$mime]) || ! $this->accepted($mime, $accept)) {
            throw new ApiException('file_type_not_allowed', __('submissions.errors.file_type_not_allowed'), 422, ['mime' => $mime]);
        }

        $this->scan($bytes, $mime);

        if (isset(self::IMAGE_TYPES[$mime])) {
            $bytes = $this->reencode($bytes, $mime);
        }

        return ['bytes' => $bytes, 'mime' => $mime, 'extension' => $allowed[$mime]];
    }

    /**
     * The original bytes are scanned (also images, before re-encoding). Infected → 422; above the scanner's
     * size limit → 422 file_too_large (never fail-open: padding must not skip the scan); no verdict → 503
     * (the Field App retries later) unless uploads.clamav.fail_open.
     */
    private function scan(string $bytes, string $mime): void
    {
        try {
            $result = $this->scanner->scan($bytes);
        } catch (UploadScanFailed $e) {
            if ($e->tooLarge) {
                throw new ApiException('file_too_large', __('submissions.errors.file_too_large'), 422);
            }
            report($e);
            if ((bool) config('uploads.clamav.fail_open', false)) {
                return;
            }
            throw new ApiException('scanner_unavailable', __('submissions.errors.scanner_unavailable'), 503);
        }

        if (! $result->clean) {
            Log::warning('upload.infected', ['signature' => $result->signature, 'mime' => $mime, 'bytes' => strlen($bytes)]);
            throw new ApiException('file_infected', __('submissions.errors.file_infected'), 422);
        }
    }

    /** @param  list<string>  $accept */
    private function accepted(string $mime, array $accept): bool
    {
        if ($accept === []) {
            return true;
        }

        return array_any($accept, fn ($pattern): bool => $pattern === $mime || (str_ends_with($pattern, '/*') && str_starts_with($mime, substr($pattern, 0, -1))));
    }

    private function reencode(string $bytes, string $mime): string
    {
        $this->guardDimensions($bytes);
        $decoded = @imagecreatefromstring($bytes);
        if ($decoded === false) {
            throw new ApiException('file_type_not_allowed', __('submissions.errors.file_type_not_allowed'), 422);
        }
        $image = $this->limitPixels(ExifOrientation::apply($decoded, ExifOrientation::read($bytes)), $mime === 'image/png');
        if ($image !== $decoded) {
            imagedestroy($decoded);
        }

        ob_start();
        match ($mime) {
            'image/png' => (function () use ($image): void {
                imagesavealpha($image, true);
                imagepng($image, null, 6);
            })(),
            'image/webp' => imagewebp($image, null, 85),
            default => imagejpeg($image, null, 85),
        };
        $out = (string) ob_get_clean();
        imagedestroy($image);

        return $out;
    }

    /** Scales an image above the print pixel limit down to it (aspect ratio kept). */
    private function limitPixels(GdImage $image, bool $alpha): GdImage
    {
        $width = imagesx($image);
        $height = imagesy($image);
        $max = max(1, (int) config('submissions.image_store_max_pixels', 25_000_000));
        if ($width * $height <= $max) {
            return $image;
        }
        $ratio = sqrt($max / ($width * $height));
        $target = imagecreatetruecolor(max(1, (int) floor($width * $ratio)), max(1, (int) floor($height * $ratio)));
        if ($alpha) {
            imagealphablending($target, false);
            imagesavealpha($target, true);
        }
        imagecopyresampled($target, $image, 0, 0, 0, 0, imagesx($target), imagesy($target), $width, $height);
        imagedestroy($image);

        return $target;
    }

    /** Decompression bombs: header dimensions are checked before GD allocates the pixel buffer. */
    private function guardDimensions(string $bytes): void
    {
        $size = @getimagesizefromstring($bytes);
        if ($size === false) {
            throw new ApiException('file_type_not_allowed', __('submissions.errors.file_type_not_allowed'), 422);
        }

        [$width, $height] = $size;
        $maxSide = (int) config('submissions.image_max_side', 12_000);
        if ($width > $maxSide || $height > $maxSide || $width * $height > (int) config('submissions.image_max_pixels', 40_000_000)) {
            throw new ApiException('image_too_large', __('submissions.errors.image_too_large'), 422, ['width' => $width, 'height' => $height]);
        }
    }
}
