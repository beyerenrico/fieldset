<?php

declare(strict_types=1);

namespace App\Domain\Audit\Listeners;

use App\Domain\Audit\AuditLogger;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\Verified;
use Illuminate\Database\Eloquent\Model;
use Laravel\Fortify\Events\TwoFactorAuthenticationFailed;

/**
 * Writes authentication events to the audit log (BUILD_PROMPT §8.7, §8.11). Password changes (including
 * resets), MFA enrolment and recovery-code use are audited by the User model itself, once each.
 */
final readonly class LogAuthEvents
{
    public function __construct(private AuditLogger $audit) {}

    public function login(Login $event): void
    {
        $this->forUser('auth.login', $event->user, ['guard' => $event->guard, 'remember' => $event->remember]);
    }

    public function logout(Logout $event): void
    {
        $this->forUser('auth.logout', $event->user, ['guard' => $event->guard]);
    }

    public function failed(Failed $event): void
    {
        $this->audit->record('auth.failed', $event->user instanceof Model ? $event->user : null, [
            'guard' => $event->guard,
            'email' => is_string($event->credentials['email'] ?? null) ? $event->credentials['email'] : null,
        ]);
    }

    public function lockout(Lockout $event): void
    {
        $this->audit->record('auth.lockout', null, ['email' => $event->request->input('email')]);
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

    /** @param  array<string, mixed>  $properties */
    private function forUser(string $event, mixed $user, array $properties = []): void
    {
        $model = $user instanceof Model ? $user : null;
        $this->audit->record($event, $model, $properties, $model);
    }
}
