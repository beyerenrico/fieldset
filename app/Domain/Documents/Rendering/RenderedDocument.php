<?php

declare(strict_types=1);

namespace App\Domain\Documents\Rendering;

/** A rendered PDF with the hash of the canonical input that produced it. */
final readonly class RenderedDocument
{
    public function __construct(
        public string $pdf,
        public string $inputHash,
        public int $pageCount,
        public string $rendererVersion,
    ) {}

    public function sha256(): string
    {
        return hash('sha256', $this->pdf);
    }
}
