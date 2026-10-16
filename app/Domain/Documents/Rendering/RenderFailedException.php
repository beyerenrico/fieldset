<?php

declare(strict_types=1);

namespace App\Domain\Documents\Rendering;

use RuntimeException;
use Throwable;

/** Gotenberg could not be reached or refused the document. Transient: the job retries. */
final class RenderFailedException extends RuntimeException
{
    public static function because(Throwable $previous): self
    {
        return new self((string) __('documents.errors.render_failed'), 0, $previous);
    }
}
