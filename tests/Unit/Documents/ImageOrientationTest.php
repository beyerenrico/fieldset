<?php

declare(strict_types=1);

use App\Domain\Documents\Rendering\ImageDownscaler;
use App\Domain\Submissions\Support\ExifOrientation;
use App\Domain\Submissions\Support\UploadInspector;

/* M3 review #10 (pixel limit for rendering) and #12 (EXIF orientation applied before re-encoding). */

/** A JPEG of $w × $h (left half red, right half blue) carrying the EXIF Orientation tag. */
function orientedJpeg(int $w, int $h, int $orientation): string
{
    $image = imagecreatetruecolor($w, $h);
    imagefilledrectangle($image, 0, 0, intdiv($w, 2) - 1, $h - 1, (int) imagecolorallocate($image, 255, 0, 0));
    imagefilledrectangle($image, intdiv($w, 2), 0, $w - 1, $h - 1, (int) imagecolorallocate($image, 0, 0, 255));
    ob_start();
    imagejpeg($image, null, 95);
    $jpeg = (string) ob_get_clean();

    $tiff = 'MM'.pack('n', 42).pack('N', 8).pack('n', 1).pack('nnN', 0x0112, 3, 1).pack('n', $orientation).pack('n', 0).pack('N', 0);
    $app1 = "Exif\0\0".$tiff;

    return substr($jpeg, 0, 2)."\xFF\xE1".pack('n', strlen($app1) + 2).$app1.substr($jpeg, 2);
}

/** @return array{0: int, 1: int, 2: array{int, int, int}} width, height and the RGB of the top-left area */
function imageFacts(string $bytes): array
{
    $image = imagecreatefromstring($bytes);
    $rgb = imagecolorat($image, 2, 2);

    return [imagesx($image), imagesy($image), [($rgb >> 16) & 0xFF, ($rgb >> 8) & 0xFF, $rgb & 0xFF]];
}

it('reads the EXIF orientation (ext-exif is part of the app image and CI)', function (): void {
    expect(extension_loaded('exif'))->toBeTrue()
        ->and(ExifOrientation::read(orientedJpeg(40, 20, 6)))->toBe(6)
        ->and(ExifOrientation::read('not an image'))->toBe(1);
});

it('applies the orientation before re-encoding an upload, then strips EXIF', function (int $orientation, int $width, int $height, string $topLeft): void {
    $out = app(UploadInspector::class)->inspect(orientedJpeg(40, 20, $orientation), 'photo', 1024 * 1024);
    [$w, $h, $rgb] = imageFacts($out['bytes']);

    expect([$w, $h])->toBe([$width, $height])
        ->and($topLeft === 'red' ? $rgb[0] > 200 && $rgb[2] < 60 : $rgb[2] > 200 && $rgb[0] < 60)->toBeTrue()
        ->and(ExifOrientation::read($out['bytes']))->toBe(1);
})->with([
    'normal' => [1, 40, 20, 'red'],
    'rotated 180' => [3, 40, 20, 'blue'],
    'rotated 90 cw' => [6, 20, 40, 'red'],
    'rotated 90 ccw' => [8, 20, 40, 'blue'],
    'mirrored' => [2, 40, 20, 'blue'],
]);

it('applies the orientation when photos are prepared for print', function (): void {
    $uri = (string) (new ImageDownscaler)->dataUri(orientedJpeg(40, 20, 6), 1600);

    expect(array_slice(imageFacts(base64_decode(substr($uri, strpos($uri, ',') + 1))), 0, 2))->toBe([20, 40]);
});

it('scales uploads above the print pixel limit down to it', function (): void {
    config(['submissions.image_store_max_pixels' => 2_000]);

    $out = app(UploadInspector::class)->inspect(orientedJpeg(100, 50, 1), 'photo', 1024 * 1024);
    [$w, $h] = imageFacts($out['bytes']);

    expect($w * $h)->toBeLessThanOrEqual(2_000)->and(round($w / $h, 1))->toBe(2.0);
});

it('refuses photos above 25 megapixels for rendering before decoding them', function (): void {
    expect(ImageDownscaler::MAX_SOURCE_PIXELS)->toBe(25_000_000)
        ->and((int) config('submissions.image_store_max_pixels'))->toBeLessThanOrEqual(ImageDownscaler::MAX_SOURCE_PIXELS);
});
