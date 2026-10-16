<?php

declare(strict_types=1);

namespace App\Domain\Forms\Import;

use RuntimeException;
use Throwable;

/**
 * A PDF cannot be imported. `reason` is a stable code with a translation under
 * `forms.import.errors.<reason>`; technical details stay in the previous exception and the log.
 */
final class PdfImportException extends RuntimeException
{
    public const array REASONS = ['too_large', 'too_many_pages', 'malformed', 'encrypted', 'no_fields', 'timeout', 'reader_failed', 'missing_file'];

    /** @param  array<string, int|string>  $params */
    public function __construct(public readonly string $reason, public readonly array $params = [], ?Throwable $previous = null)
    {
        parent::__construct((string) __('forms.import.errors.'.$reason, $params), 0, $previous);
    }
}
