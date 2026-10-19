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
        // `config set mail` reads the SMTP password with --password-stdin or --password-file (ADR 0080).
        $hint = str_contains($option, 'password') && ! str_contains($option, 'owner')
            ? 'Pipe it on stdin (--password-stdin) or pass --password-file=<path> (a file with mode 0600); command lines end up in shell history and process lists.'
            : "Pipe it on stdin or pass --{$option}-file=<path> (a file with mode 0600); command lines end up in shell history and process lists.";

        return new self("Secrets are never accepted as command-line arguments (--{$option}).", 'secret_in_argument', $hint);
    }

    public static function usage(string $message): self
    {
        return new self($message, 'invalid_usage', 'Run the command with --help for its flags.');
    }
}
