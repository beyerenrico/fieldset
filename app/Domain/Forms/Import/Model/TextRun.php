<?php

declare(strict_types=1);

namespace App\Domain\Forms\Import\Model;

/** A piece of visible page text with its approximate position (for label detection). */
final readonly class TextRun
{
    public function __construct(
        public int $page,
        public float $x,
        public float $y,
        public float $width,
        public float $size,
        public string $text,
    ) {}

    public function right(): float
    {
        return $this->x + $this->width;
    }

    public function centerY(): float
    {
        return $this->y + $this->size * 0.35;
    }
}
