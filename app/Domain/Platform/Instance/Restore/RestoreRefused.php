<?php

declare(strict_types=1);

namespace App\Domain\Platform\Instance\Restore;

use RuntimeException;
use Throwable;

/** A restore step refused or failed with a stable code for the one-off's JSON document (plan U17, ADR 0085). */
final class RestoreRefused extends RuntimeException
{
    public function __construct(public readonly string $errorCode, string $message, public readonly ?string $hint = null, ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
