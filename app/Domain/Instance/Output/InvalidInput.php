<?php

declare(strict_types=1);

namespace App\Domain\Instance\Output;

use App\Domain\Instance\InstanceException;

/** Invalid usage or input (exit 2). */
final class InvalidInput extends InstanceException
{
    /** @param  array<string, mixed>  $data  non-secret context under `data` (e.g. the flags a repeated install changed) */
    public function __construct(string $message, string $errorCode = 'invalid_input', ?string $hint = null, array $data = [])
    {
        parent::__construct($message, ExitCode::InvalidInput, $errorCode, $hint, false, $data);
    }

    /** R50/AE9: a secret was passed as a flag value. Only the flag name is echoed, never the value. */
    public static function secretFlag(string $option): self
    {
        return new self(
            "Secrets are never accepted as command-line arguments (--{$option}).",
            'secret_in_argument',
            "Pipe it on stdin or pass --{$option}-file=<path> (a file with mode 0600); command lines end up in shell history and process lists.",
        );
    }

    public static function usage(string $message): self
    {
        return new self($message, 'invalid_usage', 'Run the command with --help for its flags.');
    }
}
