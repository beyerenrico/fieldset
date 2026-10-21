<?php

declare(strict_types=1);

namespace App\Domain\Instance\State;

use App\Domain\Instance\InstanceException;
use App\Domain\Instance\Output\ExitCode;

/** The directory carries no instance marker (KTD1: every fieldset command but `version` needs one; exit 2). */
final class NotAnInstanceDirectory extends InstanceException
{
    public function __construct(string $path)
    {
        parent::__construct(
            "{$path} is not a Fieldset instance directory.",
            ExitCode::InvalidInput,
            'not_an_instance_directory',
            'Run fieldset through the shim in the instance directory, or pass --dir=<instance directory>.',
            false,
            ['dir' => $path],
        );
    }
}
