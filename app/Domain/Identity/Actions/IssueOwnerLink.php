<?php

declare(strict_types=1);

namespace App\Domain\Identity\Actions;

use App\Domain\Identity\LoginLinks\IssuedLoginLink;
use App\Domain\Identity\LoginLinks\LoginLinkKind;
use App\Domain\Identity\LoginLinks\LoginLinkRefused;
use App\Domain\Identity\LoginLinks\LoginLinks;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Sso\Contracts\PasswordAuthGate;
use App\Domain\Identity\Sso\Exceptions\SsoRequiredException;
use App\Domain\Identity\Sso\Login\BlockedPasswordFlow;

/**
 * The new owner's first sign-in link (BUILD_PROMPT_V2 E10/R27, KTD21, ADR 0056). The token is a token of the
 * password-reset broker, so its expiry (60 minutes), single use and throttle apply unchanged, and the
 * PasswordAuthGate refuses a domain that enforces SSO. The landing page leads to Filament's reset page
 * (setting a password) and then offers MFA.
 */
final readonly class IssueOwnerLink
{
    public function __construct(private LoginLinks $links, private PasswordAuthGate $gate, private BlockedPasswordFlow $blocked) {}

    /** @throws LoginLinkRefused */
    public function handle(User $user, ?int $hostUid = null): IssuedLoginLink
    {
        if (! $user->isActive()) {
            throw LoginLinkRefused::inactive();
        }
        try {
            $this->gate->assertPasswordReset($user->email);
        } catch (SsoRequiredException $e) {
            $this->blocked->reset($user->email, $e, 'login_link');

            throw LoginLinkRefused::ssoRequired();
        }

        $broker = LoginLinks::broker();
        if ($broker->getRepository()->recentlyCreatedToken($user)) {
            throw LoginLinkRefused::rateLimited($this->throttleSeconds());
        }
        $token = $broker->createToken($user);
        $expiresAt = now()->addMinutes($this->expiryMinutes());
        $link = $this->links->replace($user, LoginLinkKind::Owner, $token, $expiresAt, $hostUid);

        return new IssuedLoginLink($link, $this->links->url($token));
    }

    private function expiryMinutes(): int
    {
        return (int) config('auth.passwords.'.config('auth.defaults.passwords', 'users').'.expire', 60);
    }

    private function throttleSeconds(): int
    {
        return (int) config('auth.passwords.'.config('auth.defaults.passwords', 'users').'.throttle', 60);
    }
}
