<?php

declare(strict_types=1);

namespace App\Domain\Audit\Listeners;

use App\Domain\Audit\AuditLogger;
use App\Domain\Audit\FailedLoginAudit;
use App\Domain\Audit\LockoutAudit;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Sso\Enforcement\DomainPasswordGate;
use App\Domain\Identity\Sso\Login\LoginAuditContext;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\Verified;
use Illuminate\Database\Eloquent\Model;
use Laravel\Fortify\Events\TwoFactorAuthenticationFailed;

/**
 * Writes authentication events to the audit log (BUILD_PROMPT §8.7, §8.11). Password changes (including
 * resets), MFA enrolment and recovery-code use are audited by the User model itself, once each. Failed
 * sign-ins and lockouts are capped per IP (FailedLoginAudit, LockoutAudit): the log stays flood-proof.
 *
 * auth.login carries `method` (ADR 0036): `sso` with `provider`, `protocol`, `amr` and `email_verified_by`
 * from LoginAuditContext (set by the SSO login before Auth::login); otherwise `break_glass` when the
 * password login only passed enforced SSO as the provider's break-glass Owner — then also
 * `auth.break_glass_login` — or `password`. Exactly one auth.login entry per login.
 */
final readonly class LogAuthEvents
{
    public function __construct(private AuditLogger $audit) {}

    public function login(Login $event): void
    {
        $method = LoginAuditContext::method() ?? ($this->isBreakGlass($event->user) ? 'break_glass' : 'password');
        $this->forUser('auth.login', $event->user, [
            'guard' => $event->guard,
            'remember' => $event->remember,
            'method' => $method,
            ...LoginAuditContext::details(),
        ]);
        LoginAuditContext::clear();

        if ($method === 'break_glass') {
            $this->forUser('auth.break_glass_login', $event->user, ['guard' => $event->guard]);
        }
    }

    public function logout(Logout $event): void
    {
        $this->forUser('auth.logout', $event->user, ['guard' => $event->guard]);
    }

    public function failed(Failed $event): void
    {
        $email = $event->credentials['email'] ?? null;
        FailedLoginAudit::record($event->user instanceof Model ? $event->user : null, [
            'guard' => $event->guard,
            'email' => is_string($email) ? mb_substr(trim($email), 0, 254) : null,
        ], request()->ip());
    }

    public function lockout(Lockout $event): void
    {
        LockoutAudit::record($event->request->input('email'), 'fortify', $event->request->ip());
    }

    public function mfaFailed(TwoFactorAuthenticationFailed $event): void
    {
        $this->forUser('auth.mfa_failed', $event->user);
    }

    public function verified(Verified $event): void
    {
        $this->forUser('auth.email_verified', $event->user);
    }

    /** @return array<class-string, string> */
    public function subscribe(): array
    {
        return [
            Login::class => 'login',
            Logout::class => 'logout',
            Failed::class => 'failed',
            Lockout::class => 'lockout',
            TwoFactorAuthenticationFailed::class => 'mfaFailed',
            Verified::class => 'verified',
        ];
    }

    /** Resolved lazily: subscribers are built at boot, the gate is only needed on a login. */
    private function isBreakGlass(mixed $user): bool
    {
        return $user instanceof User && app(DomainPasswordGate::class)->bypassesEnforcement($user);
    }

    /** @param  array<string, mixed>  $properties */
    private function forUser(string $event, mixed $user, array $properties = []): void
    {
        $model = $user instanceof Model ? $user : null;
        $this->audit->record($event, $model, $properties, $model);
    }
}
