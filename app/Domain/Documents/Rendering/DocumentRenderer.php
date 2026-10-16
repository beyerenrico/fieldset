<?php

declare(strict_types=1);

namespace App\Domain\Documents\Rendering;

use FormSchema\Json\Json;
use UnexpectedValueException;

/**
 * The renderer (§8.5): canonical input → HTML (Blade + print CSS, assets inlined) → budget check →
 * Gotenberg → PDF. `input_hash` = sha256 of the canonical HTML, data and versions; Chromium stamps
 * dates into every PDF, so file hashes are never compared across renders.
 */
final readonly class DocumentRenderer
{
    /**
     * Bump whenever templates or CSS change in a way that alters output.
     * 1.1.0: tagged PDF, heading outline, text alternatives, locale tag, readable brand colors, table thumbnails.
     */
    public const string VERSION = '1.1.0';

    public function __construct(private HtmlComposer $composer, private GotenbergClient $gotenberg) {}

    public function render(RenderInput $input): RenderedDocument
    {
        return $this->convert($this->prepare($input));
    }

    /** Composes the HTML and checks the request budget; no PDF engine call yet. */
    public function prepare(RenderInput $input): PreparedDocument
    {
        $html = $this->composer->compose($input);
        self::assertWithinBudget($html);

        return new PreparedDocument($input, $html, self::inputHash($input, $html));
    }

    public function convert(PreparedDocument $prepared): RenderedDocument
    {
        $pdf = $this->gotenberg->convert($prepared->html, $prepared->input->design);
        if (! PdfInfo::isPdf($pdf)) {
            throw RenderFailedException::because(new UnexpectedValueException('Gotenberg did not return a PDF.'));
        }

        return new RenderedDocument($pdf, $prepared->inputHash, PdfInfo::pageCount($pdf), self::VERSION);
    }

    public static function inputHash(RenderInput $input, ComposedHtml $html): string
    {
        return hash('sha256', Json::canonical([
            'html' => $html->hashes(),
            'input' => $input->canonical(),
            'renderer' => self::VERSION,
        ]));
    }

    public static function assertWithinBudget(ComposedHtml $html): void
    {
        $limit = (int) config('documents.max_request_bytes', 25 * 1024 * 1024);
        if ($html->bytes() > $limit) {
            throw RenderBudgetExceededException::make($html->bytes(), $limit);
        }
    }
}
