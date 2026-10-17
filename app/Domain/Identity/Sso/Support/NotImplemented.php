<?php

declare(strict_types=1);

namespace App\Domain\Identity\Sso\Support;

use LogicException;

/**
 * M7 skeleton helper: stubs with their final signatures pass their arguments here until the owning agent
 * implements them. Deliberately not final (Rector would otherwise drop the "unused" stub parameters and
 * rewrite void stubs to `never`). Delete it once no stub calls it.
 */
abstract class NotImplemented
{
    public static function exception(string $method, mixed ...$arguments): LogicException
    {
        return new LogicException('not implemented');
    }

    /** For void stubs. */
    public static function raise(string $method, mixed ...$arguments): void
    {
        throw self::exception($method, ...$arguments);
    }
}
