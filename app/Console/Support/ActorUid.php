<?php

declare(strict_types=1);

namespace App\Console\Support;

use InvalidArgumentException;

/** `--actor-uid`: the host UID of whoever ran the `fsa` command, recorded in audit entries (KTD21, KTD26). */
final class ActorUid
{
    /** @throws InvalidArgumentException for anything but a non-negative integer */
    public static function from(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (! is_string($value) || preg_match('/^\d{1,10}$/', $value) !== 1 || (int) $value > 4_294_967_295) {
            throw new InvalidArgumentException('--actor-uid must be a numeric host UID.');
        }

        return (int) $value;
    }
}
