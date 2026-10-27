<?php

declare(strict_types=1);

namespace App\Domain\Instance\Doctor;

/**
 * Outcome of one doctor check (R10): pass, warn (will likely bite later), fail, not applicable, or unknown (the check could
 * not find out, e.g. the owner measurement could not run; 2.1.1 U4, ADR 0078). Unknown counts like warn: the command
 * passes with exit 0 and lists it under `warnings`.
 */
enum CheckStatus: string
{
    case Ok = 'ok';
    case Warn = 'warn';
    case Fail = 'fail';
    case Skipped = 'skipped';
    case Unknown = 'unknown';
}
