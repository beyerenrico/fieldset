<?php

declare(strict_types=1);

namespace App\Domain\Forms\Support;

use RuntimeException;

/** The stored draft is no longer the one the caller checked (another tab or builder saved meanwhile). */
final class DraftChangedException extends RuntimeException
{
    public function __construct(public readonly string $expected, public readonly string $stored)
    {
        parent::__construct(__('forms.canvas.stale_action'));
    }
}
