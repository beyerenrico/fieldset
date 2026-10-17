<?php

declare(strict_types=1);

namespace App\Domain\Identity\Sso\Exceptions;

use App\Domain\Identity\Sso\Data\DiscoveryResult;
use RuntimeException;

/** Thrown by PasswordAuthGate: the email's domain is verified by an organization that enforces SSO. */
final class SsoRequiredException extends RuntimeException
{
    public function __construct(public readonly DiscoveryResult $discovery)
    {
        parent::__construct('Single sign-on is required for this email domain.');
    }
}
