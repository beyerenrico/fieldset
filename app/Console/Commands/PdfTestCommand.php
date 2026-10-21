<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Support\JsonResult;
use App\Domain\Documents\Designs\DesignSettings;
use App\Domain\Documents\Rendering\ComposedHtml;
use App\Domain\Documents\Rendering\GotenbergClient;
use App\Domain\Documents\Rendering\RenderFailedException;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * The PDF check of the install smoke test (R11, KTD25, ADR 0072 amendment): a socket-less one-off renders a one-page
 * document through the app's own Gotenberg client (the path every rendition takes) and checks that a PDF came back.
 * Nothing is stored. Exit 0 with `data.bytes` when it did; `pdf_render_failed` otherwise.
 */
#[Description('Render a one-page test PDF through Gotenberg (fieldset install smoke test)')]
#[Signature('instance:pdf-test
        {--json : Print one JSON document on stdout}')]
final class PdfTestCommand extends Command
{
    private const string NAME = 'instance:pdf-test';

    private const string HTML = '<!doctype html><html lang="en"><head><meta charset="utf-8"><title>form-schema-app</title></head>'
        .'<body><h1>form-schema-app</h1><p>Install smoke test: this page was rendered by Gotenberg.</p></body></html>';

    public function handle(GotenbergClient $client): int
    {
        try {
            $pdf = $client->convert(new ComposedHtml(self::HTML, '', ''), DesignSettings::defaults());
        } catch (RenderFailedException $e) {
            return $this->refuse('Gotenberg did not render the test PDF: '.$e->getMessage());
        }
        if (! str_starts_with($pdf, '%PDF-')) {
            return $this->refuse('Gotenberg answered, but not with a PDF ('.strlen($pdf).' bytes).');
        }
        if ($this->option('json') === true) {
            return JsonResult::ok(self::NAME, ['bytes' => strlen($pdf)])->emit($this);
        }
        $this->components->info('Test PDF rendered ('.strlen($pdf).' bytes).');

        return self::SUCCESS;
    }

    private function refuse(string $message): int
    {
        $hint = 'Check that the gotenberg service runs (`fieldset logs gotenberg`) and that the app reaches it.';
        if ($this->option('json') === true) {
            return JsonResult::failed(self::NAME, 'pdf_render_failed', $message, $hint, true)->emit($this);
        }
        $this->components->error($message);

        return self::FAILURE;
    }
}
