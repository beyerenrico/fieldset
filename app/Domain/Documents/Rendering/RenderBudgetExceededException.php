<?php

declare(strict_types=1);

namespace App\Domain\Documents\Rendering;

use RuntimeException;

/** The inlined document is larger than the render request budget (§8.5); retrying cannot help. */
final class RenderBudgetExceededException extends RuntimeException
{
    public static function make(int $bytes, int $limit): self
    {
        return new self((string) __('documents.errors.budget_exceeded', [
            'size' => number_format($bytes / 1048576, 1),
            'limit' => number_format($limit / 1048576, 1),
        ]));
    }
}
