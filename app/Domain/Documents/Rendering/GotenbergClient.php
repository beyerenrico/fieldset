<?php

declare(strict_types=1);

namespace App\Domain\Documents\Rendering;

use App\Domain\Documents\Designs\DesignSettings;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Spatie\LaravelPdf\Enums\Orientation;
use Spatie\LaravelPdf\Enums\Unit;
use Spatie\LaravelPdf\Exceptions\CouldNotGeneratePdf;
use Spatie\LaravelPdf\PdfBuilder;

/**
 * HTML → PDF through Gotenberg's Chromium route via spatie/laravel-pdf's Gotenberg driver (ADR 0028).
 * The driver posts only index.html, header.html and footer.html, which is why every asset is inlined.
 * Paper, orientation and margins come from the design version.
 */
final class GotenbergClient
{
    public function convert(ComposedHtml $html, DesignSettings $design): string
    {
        $margins = $design->margins;
        $builder = (new PdfBuilder)
            ->driver('gotenberg')
            ->html($html->body)
            ->headerHtml($html->header)
            ->footerHtml($html->footer)
            ->format($design->paper)
            ->margins($margins['top'], $margins['right'], $margins['bottom'], $margins['left'], Unit::Millimeter)
            ->orientation($design->orientation === 'landscape' ? Orientation::Landscape : Orientation::Portrait);

        try {
            return $builder->generatePdfContent();
        } catch (CouldNotGeneratePdf|ConnectionException|RequestException $e) {
            throw RenderFailedException::because($e);
        }
    }
}
