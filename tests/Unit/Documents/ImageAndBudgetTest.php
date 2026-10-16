<?php

declare(strict_types=1);

use App\Domain\Documents\Rendering\ComposedHtml;
use App\Domain\Documents\Rendering\DocumentRenderer;
use App\Domain\Documents\Rendering\ImageDownscaler;
use App\Domain\Documents\Rendering\PdfInfo;
use App\Domain\Documents\Rendering\RenderBudgetExceededException;

function docJpegOf(int $w, int $h): string
{
    $image = imagecreatetruecolor($w, $h);
    ob_start();
    imagejpeg($image);

    return (string) ob_get_clean();
}

function docDecodeDataUri(string $uri): array
{
    $size = getimagesizefromstring(base64_decode(substr($uri, strpos($uri, ',') + 1)));

    return [$size[0], $size[1], $size['mime']];
}

it('downscales photos to a long edge of at most 1600 px', function (): void {
    $uri = (new ImageDownscaler)->dataUri(docJpegOf(4000, 3000), 1600);

    expect($uri)->toStartWith('data:image/jpeg;base64,')
        ->and(docDecodeDataUri($uri))->toBe([1600, 1200, 'image/jpeg'])
        ->and(ImageDownscaler::targetSize(1200, 3000, 1600))->toBe([640, 1600])
        ->and(ImageDownscaler::targetSize(800, 600, 1600))->toBe([800, 600]);
});

it('keeps small images and PNG transparency', function (): void {
    $png = imagecreatetruecolor(300, 100);
    imagesavealpha($png, true);
    imagefill($png, 0, 0, (int) imagecolorallocatealpha($png, 0, 0, 0, 127));
    ob_start();
    imagepng($png);
    $uri = (new ImageDownscaler)->dataUri((string) ob_get_clean(), 1600);

    expect(docDecodeDataUri((string) $uri))->toBe([300, 100, 'image/png'])
        ->and((new ImageDownscaler)->dataUri('not an image', 1600))->toBeNull();
});

it('enforces the render request budget with a clear error', function (): void {
    config(['documents.max_request_bytes' => 1000]);
    $small = new ComposedHtml(str_repeat('a', 400), str_repeat('b', 300), str_repeat('c', 300));
    $large = new ComposedHtml(str_repeat('a', 2 * 1024 * 1024), '', '');

    DocumentRenderer::assertWithinBudget($small);
    expect(fn () => DocumentRenderer::assertWithinBudget($large))
        ->toThrow(RenderBudgetExceededException::class, 'The document is too large to render (2.0 MB of 0.0 MB allowed)');
});

it('counts the pages of a PDF', function (): void {
    expect(PdfInfo::pageCount("%PDF-1.4\n<</Type /Pages /Kids [3 0 R 4 0 R] /Count 2>>\n<</Type /Page /Parent 2 0 R>>\n<</Type/Page>>"))->toBe(2)
        ->and(PdfInfo::pageCount('%PDF-1.4 /Count 5'))->toBe(5)
        ->and(PdfInfo::isPdf('<html>'))->toBeFalse();
});
