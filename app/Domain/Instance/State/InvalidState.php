<?php

declare(strict_types=1);

namespace App\Domain\Instance\State;

use App\Domain\Instance\InstanceException;
use App\Domain\Instance\Output\ExitCode;
use Throwable;

/** An instance file is missing, unreadable or malformed (preflight failure, exit 3). */
final class InvalidState extends InstanceException
{
    public function __construct(string $message, ?Throwable $previous = null)
    {
        parent::__construct(
            $message,
            ExitCode::PreflightFailed,
            'invalid_instance_state',
            'Run `fsa doctor`; restore the file from a backup if it was edited by hand.',
            false,
            [],
            $previous,
        );
    }
}
