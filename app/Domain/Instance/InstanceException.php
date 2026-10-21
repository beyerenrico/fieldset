<?php

declare(strict_types=1);

namespace App\Domain\Instance;

use App\Domain\Instance\Output\ExitCode;
use RuntimeException;
use Throwable;

/**
 * Base failure of a `fieldset` operation (ADR 0066). It carries the exit code and the machine-readable error of the
 * result envelope, so the console base can render any failure without knowing where it came from, and whether the
 * failed operation had already changed something (`changed` in the envelope, e.g. a partial uninstall). Messages
 * must never contain secret values.
 */
class InstanceException extends RuntimeException
{
    /** @param  array<string, mixed>  $data  extra, non-secret context rendered under `data` */
    public function __construct(
        string $message,
        public readonly ExitCode $exitCode = ExitCode::InternalError,
        public readonly string $errorCode = 'internal_error',
        public readonly ?string $hint = null,
        public readonly bool $retryable = false,
        public readonly array $data = [],
        ?Throwable $previous = null,
        public readonly bool $changed = false,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
