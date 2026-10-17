<?php

declare(strict_types=1);

namespace App\Domain\Identity\LoginLinks;

use App\Domain\Audit\AuditLogger;
use App\Domain\Identity\Models\LoginLink;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Sso\Contracts\PasswordAuthGate;
use App\Domain\Identity\Sso\Exceptions\SsoRequiredException;
use App\Domain\Identity\Sso\Login\BlockedPasswordFlow;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use SensitiveParameter;

/**
 * Consumes a posted sign-in link token (ADR 0056): rate-limited per IP, then used / expired / invalid, then the
 * PasswordAuthGate (a domain that switched on SSO enforcement after issuing refuses the link), and for an owner
 * link the reset broker must still hold the token. A valid link is marked used with time and IP, under a row
 * lock, and audited as `login_link.used`.
 */
final readonly class ConsumeLoginLink
{
    public const int MAX_ATTEMPTS_PER_MINUTE = 20;

    private const string TOKEN_PATTERN = '/^[A-Za-z0-9]{32,128}$/';

    public function __construct(private PasswordAuthGate $gate, private BlockedPasswordFlow $blocked, private AuditLogger $audit) {}

    public function handle(#[SensitiveParameter] string $token, string $ip): LoginLinkResult
    {
        $key = 'login-link:consume:'.hash('sha256', $ip);
        if (RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS_PER_MINUTE)) {
            return LoginLinkResult::refused(LoginLinkState::RateLimited, RateLimiter::availableIn($key));
        }
        RateLimiter::hit($key, 60);

        if (preg_match(self::TOKEN_PATTERN, $token) !== 1) {
            return LoginLinkResult::refused(LoginLinkState::Invalid);
        }

        return DB::transaction(function () use ($token, $ip): LoginLinkResult {
            $link = LoginLink::query()->where('token_hash', LoginLink::hashToken($token))->lockForUpdate()->first();
            if (! $link instanceof LoginLink) {
                return LoginLinkResult::refused(LoginLinkState::Invalid);
            }
            $refusal = $this->refusal($link, $token);
            if ($refusal instanceof LoginLinkState) {
                return LoginLinkResult::refused($refusal);
            }

            $link->forceFill(['used_at' => now(), 'used_ip' => $ip])->save();
            $this->audit->record('login_link.used', $link->user, ['kind' => $link->kind->value, 'email' => $link->user->email], $link->user);

            return LoginLinkResult::consumed($link, $link->user);
        });
    }

    private function refusal(LoginLink $link, #[SensitiveParameter] string $token): ?LoginLinkState
    {
        if ($link->isUsed()) {
            return LoginLinkState::Used;
        }
        if ($link->isExpired()) {
            return LoginLinkState::Expired;
        }
        $user = $link->user;
        if (! $user->isActive()) {
            return LoginLinkState::Invalid;
        }
        if ($this->ssoRequired($link, $user)) {
            return LoginLinkState::SsoRequired;
        }
        if ($link->kind === LoginLinkKind::Owner) {
            return LoginLinks::broker()->tokenExists($user, $token) ? null : LoginLinkState::Invalid;
        }

        return LoginLinks::isAdmin($user) ? null : LoginLinkState::Invalid;
    }

    /** The existing gate: no password set (owner) or password-less sign-in (admin) for an enforced domain. */
    private function ssoRequired(LoginLink $link, User $user): bool
    {
        try {
            $link->kind === LoginLinkKind::Owner ? $this->gate->assertPasswordReset($user->email) : $this->gate->assertPasswordLogin($user->email);
        } catch (SsoRequiredException $e) {
            $link->kind === LoginLinkKind::Owner ? $this->blocked->reset($user->email, $e, 'login_link') : $this->blocked->login($user->email, $e, 'login_link');

            return true;
        }

        return false;
    }
}
