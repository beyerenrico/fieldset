<?php

declare(strict_types=1);

namespace App\Domain\Instance\State;

use App\Domain\Instance\InstanceException;
use App\Domain\Instance\Output\ExitCode;

/**
 * A path in the instance directory is a symlink, resolves outside the directory or cannot be written safely
 * (preflight failure, exit 3). The socket holder never follows such paths.
 */
final class UnsafePath extends InstanceException
{
    public function __construct(string $path, string $reason)
    {
        parent::__construct(
            "Refusing to use {$path}: {$reason}.",
            ExitCode::PreflightFailed,
            'unsafe_path',
            'Remove the symlink or fix the permissions; the instance directory must be owned by you and mode 0700.',
            false,
            ['path' => $path],
        );
    }
}
