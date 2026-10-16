<?php

declare(strict_types=1);

namespace App\Domain\Documents\Rendering;

/** Composed HTML plus the input hash, before the PDF engine runs (lets callers dedupe on the hash). */
final readonly class PreparedDocument
{
    public function __construct(public RenderInput $input, public ComposedHtml $html, public string $inputHash) {}
}
