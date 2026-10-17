<?php

declare(strict_types=1);

namespace App\Domain\Privacy\Retention;

/** Outcome of one retention run in one organization; `more` = a batch limit was hit, run again. */
final readonly class RetentionRun
{
    public function __construct(
        public int $anonymized = 0,
        public int $deleted = 0,
        public int $heldForms = 0,
        public bool $more = false,
    ) {}

    public function plus(int $anonymized, int $deleted, int $heldForms, bool $more): self
    {
        return new self($this->anonymized + $anonymized, $this->deleted + $deleted, $this->heldForms + $heldForms, $this->more || $more);
    }
}
