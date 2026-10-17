<?php

declare(strict_types=1);

namespace App\Domain\Identity\Sso\Domains;

use InvalidArgumentException;

/** A claimed SSO domain is not an exact registrable host name; `reason` is invalid|ip_address|public_suffix. */
final class InvalidDomainName extends InvalidArgumentException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct('Invalid SSO domain: '.$reason);
    }
}
