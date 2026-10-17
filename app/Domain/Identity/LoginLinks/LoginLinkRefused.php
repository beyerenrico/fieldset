<?php

declare(strict_types=1);

namespace App\Domain\Identity\LoginLinks;

use RuntimeException;

/** A sign-in link that may not be issued; `reason` is the stable error code of the command's JSON. */
final class LoginLinkRefused extends RuntimeException
{
    private function __construct(public readonly string $reason, string $message, public readonly ?int $retryAfter = null)
    {
        parent::__construct($message);
    }

    public static function notAdmin(): self
    {
        return new self('not_admin', 'The account is not an active admin of any organization.');
    }

    public static function inactive(): self
    {
        return new self('inactive', 'The account is deactivated: reactivate it first.');
    }

    public static function ssoRequired(): self
    {
        return new self('sso_required', 'The email domain enforces single sign-on: sign in through the organization\'s identity provider.');
    }

    public static function rateLimited(int $seconds): self
    {
        return new self('rate_limited', "Too many sign-in links for this account. Try again in {$seconds} seconds.", $seconds);
    }
}
