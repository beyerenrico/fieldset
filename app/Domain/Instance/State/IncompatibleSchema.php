<?php

declare(strict_types=1);

namespace App\Domain\Instance\State;

use App\Domain\Instance\InstanceException;
use App\Domain\Instance\Output\ExitCode;

/** A file written by a newer major version of fieldset (exit 7); it is never rewritten. */
final class IncompatibleSchema extends InstanceException
{
    public function __construct(string $document, string $found, string $supported)
    {
        parent::__construct(
            "The {$document} uses schema {$found}, newer than this fieldset supports ({$supported}).",
            ExitCode::Incompatible,
            'incompatible_schema',
            'Run the fieldset of the version that wrote it (the shim pins it in the instance state).',
            false,
            ['document' => $document, 'found' => $found, 'supported' => $supported],
        );
    }
}
