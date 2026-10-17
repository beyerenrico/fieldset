<?php

declare(strict_types=1);

namespace App\Domain\Identity\Sso\Domains;

use RuntimeException;

/** The TXT lookup itself failed (timeout, SERVFAIL); a missing record is an empty result, not this. */
final class TxtLookupFailed extends RuntimeException
{
    public function __construct(public readonly string $name)
    {
        parent::__construct('DNS TXT lookup failed for '.$name);
    }
}
