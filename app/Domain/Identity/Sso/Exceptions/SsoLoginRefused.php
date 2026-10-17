<?php

declare(strict_types=1);

namespace App\Domain\Identity\Sso\Exceptions;

use App\Domain\Identity\Sso\Enums\SsoRefusal;
use RuntimeException;

/** Thrown by CompleteSsoLogin; the reason is the error code the client shows (ADR 0036). */
final class SsoLoginRefused extends RuntimeException
{
    public function __construct(public readonly SsoRefusal $reason)
    {
        parent::__construct('SSO login refused: '.$reason->value);
    }
}
