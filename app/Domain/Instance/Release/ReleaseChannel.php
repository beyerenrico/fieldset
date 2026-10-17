<?php

declare(strict_types=1);

namespace App\Domain\Instance\Release;

/** Release channel, derived from the version (BUILD_PROMPT_V2 §6.5, ADR 0047). */
enum ReleaseChannel: string
{
    case Stable = 'stable';
    case Alpha = 'alpha';
    case Beta = 'beta';
    case Rc = 'rc';
}
