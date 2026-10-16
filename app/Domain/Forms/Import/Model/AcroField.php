<?php

declare(strict_types=1);

namespace App\Domain\Forms\Import\Model;

/**
 * One terminal AcroForm field (all its widgets merged), as read from the PDF. Values filled into the
 * PDF are deliberately not part of this model, so sample data can never reach a definition (§8.13).
 */
final readonly class AcroField
{
    public const int FF_READ_ONLY = 1;

    public const int FF_REQUIRED = 2;

    public const int FF_MULTILINE = 1 << 12;

    public const int FF_PASSWORD = 1 << 13;

    public const int FF_RADIO = 1 << 15;

    public const int FF_PUSHBUTTON = 1 << 16;

    public const int FF_COMBO = 1 << 17;

    public const int FF_EDIT = 1 << 18;

    public const int FF_FILE_SELECT = 1 << 20;

    public const int FF_MULTI_SELECT = 1 << 21;

    public const int FF_COMB = 1 << 24;

    /** Annotation flags Hidden (2) and NoView (32). */
    public const int AF_HIDDEN = 2 | 32;

    /**
     * @param  string  $type  Tx, Btn, Ch or Sig
     * @param  array{0: float, 1: float, 2: float, 3: float}  $rect  [x1, y1, x2, y2] in PDF points
     * @param  list<array{value: string, label: string}>  $options  choice options or radio export values
     */
    public function __construct(
        public string $name,
        public string $type,
        public int $flags,
        public int $annotationFlags,
        public int $page,
        public array $rect,
        public ?string $tooltip = null,
        public ?int $maxLength = null,
        public array $options = [],
        public ?string $formatScript = null,
        public ?string $keystrokeScript = null,
        public ?string $calculateScript = null,
        public int $widgets = 1,
    ) {}

    public function has(int $flag): bool
    {
        return ($this->flags & $flag) !== 0;
    }

    public function isHidden(): bool
    {
        return ($this->annotationFlags & self::AF_HIDDEN) !== 0;
    }

    public function isPushButton(): bool
    {
        return $this->type === 'Btn' && $this->has(self::FF_PUSHBUTTON);
    }

    public function isRadio(): bool
    {
        return $this->type === 'Btn' && ! $this->isPushButton() && $this->has(self::FF_RADIO);
    }

    public function isCheckbox(): bool
    {
        return $this->type === 'Btn' && ! $this->isPushButton() && ! $this->has(self::FF_RADIO);
    }

    public function width(): float
    {
        return $this->rect[2] - $this->rect[0];
    }

    public function height(): float
    {
        return $this->rect[3] - $this->rect[1];
    }

    public function centerY(): float
    {
        return ($this->rect[1] + $this->rect[3]) / 2;
    }
}
