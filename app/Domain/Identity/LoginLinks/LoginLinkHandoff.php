<?php

declare(strict_types=1);

namespace App\Domain\Identity\LoginLinks;

use App\Domain\Identity\Models\LoginLink;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Sso\Login\LoginAuditContext;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\URL;
use SensitiveParameter;

/**
 * Where a consumed sign-in link continues (ADR 0056, ADR 0068). Only ever the web guard of /admin: a link never
 * writes the /platform session mark (PlatformSession) and never impersonates.
 * - Owner link: the reset token waits in the session and Filament's reset page (signed URL with the email only,
 *   never the token) picks it up; after the password is set the owner is signed in and offered MFA.
 * - Admin link, MFA enrolled: a short-lived mark in this session lets the admin login show its MFA challenge
 *   for that user instead of the password step; the session is signed in only after a correct code.
 * - Admin link without MFA: signed in right away (new session id).
 */
final readonly class LoginLinkHandoff
{
    public const string RESET_KEY = 'login_link.reset';

    public const string MFA_KEY = 'login_link.mfa';

    /** How long the reset token or the MFA mark waits in the session. */
    public const int MINUTES = 10;

    public function __construct(private Session $session) {}

    /** The URL to continue at; signs the user in when nothing else is needed. */
    public function continue(LoginLink $link, User $user, #[SensitiveParameter] string $token): string
    {
        $until = now()->addMinutes(self::MINUTES)->getTimestamp();
        if ($link->kind === LoginLinkKind::Owner) {
            $this->session->put(self::RESET_KEY, ['email' => $user->email, 'token' => $token, 'until' => $until]);

            return URL::temporarySignedRoute('filament.admin.auth.password-reset.reset', now()->addMinutes(self::MINUTES), ['email' => $user->email]);
        }
        if (self::requiresMfa($user)) {
            $this->session->put(self::MFA_KEY, ['user_id' => $user->id, 'link_id' => $link->id, 'until' => $until]);

            return route('filament.admin.auth.login');
        }
        $this->signIn($user, LoginLinkKind::Admin);

        return url('/admin');
    }

    /** The reset token for Filament's reset page, once, when the email matches the one the link was for. */
    public function pullResetToken(?string $email): ?string
    {
        $pending = $this->session->pull(self::RESET_KEY);
        if (! is_array($pending) || ! is_string($pending['token'] ?? null) || ! $this->fresh($pending)) {
            return null;
        }

        return is_string($email) && hash_equals((string) ($pending['email'] ?? ''), $email) ? $pending['token'] : null;
    }

    /** The admin waiting for the MFA challenge in this session, if the mark is fresh and the account still active. */
    public function pendingMfaUser(): ?User
    {
        $pending = $this->session->get(self::MFA_KEY);
        if (! is_array($pending) || ! $this->fresh($pending) || ! is_int($pending['user_id'] ?? null)) {
            return null;
        }
        $user = User::query()->find($pending['user_id']);

        return $user instanceof User && $user->isActive() && self::requiresMfa($user) ? $user : null;
    }

    public function forgetMfa(): void
    {
        $this->session->forget(self::MFA_KEY);
    }

    /** Signs the user in on the web guard (new session id); audited as auth.login with method login_link. */
    public function signIn(User $user, LoginLinkKind $kind): void
    {
        $this->forgetMfa();
        LoginAuditContext::loginLink($kind->value);
        Auth::guard('web')->login($user);
        $this->session->regenerate();
    }

    /** Filament's app MFA (shared TOTP, ADR 0016) counts as enrolled once the secret is confirmed. */
    public static function requiresMfa(User $user): bool
    {
        return $user->two_factor_secret !== null && $user->two_factor_confirmed_at !== null;
    }

    /** @param  array<array-key, mixed>  $pending */
    private function fresh(array $pending): bool
    {
        return is_int($pending['until'] ?? null) && $pending['until'] >= now()->getTimestamp();
    }
}
