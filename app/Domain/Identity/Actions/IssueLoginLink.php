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
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * `fsa login-link <email>` (BUILD_PROMPT_V2 R28, ADR 0056): a one-time sign-in link for an existing admin.
 * Single use, valid for at most 15 minutes, stored hashed, rate-limited per account, and a newer link
 * replaces older ones. Refused for a domain that enforces SSO (PasswordAuthGate, audited like a refused
 * password login). Issuing is audited with actor `cli` and the host UID.
 */
final readonly class IssueLoginLink
{
    public const int MINUTES = 15;

    /** Links per account within one link lifetime. */
    public const int MAX_PER_WINDOW = 5;

    public function __construct(private LoginLinks $links, private PasswordAuthGate $gate, private BlockedPasswordFlow $blocked) {}

    /** @throws LoginLinkRefused */
    public function handle(User $user, ?int $hostUid = null): IssuedLoginLink
    {
        if (! $user->isActive()) {
            throw LoginLinkRefused::inactive();
        }
        if (! LoginLinks::isAdmin($user)) {
            throw LoginLinkRefused::notAdmin();
        }
        try {
            $this->gate->assertPasswordLogin($user->email);
        } catch (SsoRequiredException $e) {
            $this->blocked->login($user->email, $e, 'login_link');

            throw LoginLinkRefused::ssoRequired();
        }
        $this->throttle($user);

        $token = Str::random(64);
        $link = $this->links->replace($user, LoginLinkKind::Admin, $token, now()->addMinutes(self::MINUTES), $hostUid);

        return new IssuedLoginLink($link, $this->links->url($token));
    }

    /** @throws LoginLinkRefused */
    private function throttle(User $user): void
    {
        $key = 'login-link:issue:'.$user->id;
        if (RateLimiter::tooManyAttempts($key, self::MAX_PER_WINDOW)) {
            throw LoginLinkRefused::rateLimited(RateLimiter::availableIn($key));
        }
        RateLimiter::hit($key, self::MINUTES * 60);
    }
}
