<?php

declare(strict_types=1);

namespace App\Domain\Instance\Doctor;

/** Outcome of one doctor check (R10): pass, warn (cannot tell, or will likely bite later), fail, or not applicable. */
enum CheckStatus: string
{
    case Ok = 'ok';
    case Warn = 'warn';
    case Fail = 'fail';
    case Skipped = 'skipped';
}
