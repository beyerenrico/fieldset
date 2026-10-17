<?php

declare(strict_types=1);

namespace App\Domain\Submissions\Support;

use RuntimeException;

/**
 * The upload scanner gave no verdict (connection, timeout or protocol error). `tooLarge`: the file
 * exceeds the scanner's own limit (clamd StreamMaxLength) — never accepted unscanned, even fail-open.
 */
final class UploadScanFailed extends RuntimeException
{
    public function __construct(string $message, public readonly bool $tooLarge = false)
    {
        parent::__construct($message);
    }
}
