<?php

declare(strict_types=1);

namespace App\Domain\Platform\Instance\Upgrade;

use RuntimeException;
use Throwable;

/** An upgrade step on the app side refused or failed, with a stable code for the one-off's JSON document (ADR 0086). */
final class UpgradeRefused extends RuntimeException
{
    public function __construct(public readonly string $errorCode, string $message, public readonly ?string $hint = null, ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
