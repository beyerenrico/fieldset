<?php

declare(strict_types=1);

namespace App\Domain\Submissions\Support;

use App\Http\Api\ApiException;
use finfo;

/**
 * Upload validation (BUILD_PROMPT §11): content sniffing (never the client header), a per-kind MIME
 * allow-list, size limits, and image re-encoding with GD (drops EXIF and any embedded payload).
 */
final class UploadInspector
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

    /**
     * @param  list<string>  $accept  element `validation.accept` (MIME types or type/* wildcards)
     * @return array{bytes: string, mime: string, extension: string}
     */
    public function inspect(string $bytes, string $kind, int $maxBytes, array $accept = []): array
    {
        if (strlen($bytes) === 0 || strlen($bytes) > min($maxBytes, self::MAX_BYTES)) {
            throw new ApiException('file_too_large', __('submissions.errors.file_too_large'), 422);
        }

        $mime = (string) (new finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
        $allowed = match ($kind) {
            'photo' => self::IMAGE_TYPES,
            'signature' => ['image/png' => 'png'],
            default => self::IMAGE_TYPES + self::FILE_TYPES,
        };

        if (! isset($allowed[$mime]) || ! self::accepted($mime, $accept)) {
            throw new ApiException('file_type_not_allowed', __('submissions.errors.file_type_not_allowed'), 422, ['mime' => $mime]);
        }

        if (isset(self::IMAGE_TYPES[$mime])) {
            $bytes = $this->reencode($bytes, $mime);
        }

        return ['bytes' => $bytes, 'mime' => $mime, 'extension' => $allowed[$mime]];
    }

    /** @param  list<string>  $accept */
    private static function accepted(string $mime, array $accept): bool
    {
        if ($accept === []) {
            return true;
        }
        foreach ($accept as $pattern) {
            if ($pattern === $mime || (str_ends_with($pattern, '/*') && str_starts_with($mime, substr($pattern, 0, -1)))) {
                return true;
            }
        }

        return false;
    }

    private function reencode(string $bytes, string $mime): string
    {
        $image = @imagecreatefromstring($bytes);
        if ($image === false) {
            throw new ApiException('file_type_not_allowed', __('submissions.errors.file_type_not_allowed'), 422);
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
}
