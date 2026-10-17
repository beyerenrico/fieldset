<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages\Auth;

use App\Domain\Audit\AuditLogger;
use App\Domain\Audit\LockoutAudit;
use App\Domain\Identity\Sso\Contracts\PasswordAuthGate;
use App\Domain\Identity\Sso\Exceptions\SsoRequiredException;
use App\Domain\Identity\Sso\Login\BlockedPasswordFlow;
use DanHarrin\LivewireRateLimiting\Exceptions\TooManyRequestsException;
use Filament\Auth\Http\Responses\Contracts\LoginResponse;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
use SensitiveParameter;

/**
 * What every Filament password login of this app adds to Filament's own (the /admin and /platform logins):
 * - §8.11: Filament throttles without a Lockout event and fails a wrong MFA code without an auth event, so
 *   both are audited here (`auth.lockout` once per throttle window via LockoutAudit, `auth.mfa_failed`);
 * - ADR 0036: before any password check the PasswordAuthGate refuses an address whose domain enforces SSO
 *   (audited `auth.password_login_blocked`), except the valid break-glass Owner; the error sits on the email field.
 * `via` in the entries names the login (auditVia()).
 */
trait SecuresPasswordLogin
{
    /** `via` of the audit entries: 'admin' or 'platform'. */
    abstract protected function auditVia(): string;

    /** The error on the email field after the gate refused a password login (also the place for an SSO link). */
    abstract protected function passwordLoginRefused(SsoRequiredException $e): string;

    /** Filament's sign-in, with a wrong MFA code audited like Fortify's two-factor challenge. */
    protected function authenticateAudited(): ?LoginResponse
    {
        $challenged = $this->userUndertakingMultiFactorAuthentication !== null;
        try {
            return parent::authenticate();
        } catch (ValidationException $e) {
            $user = $challenged && $this->userUndertakingMultiFactorAuthentication !== null ? $this->getUserUndertakingMultiFactorAuthentication() : null;
            if ($user instanceof Model) {
                app(AuditLogger::class)->record('auth.mfa_failed', $user, ['via' => $this->auditVia()], $user);
            }

            throw $e;
        }
    }

    protected function getRateLimitedNotification(TooManyRequestsException $exception): ?Notification
    {
        LockoutAudit::record($this->data['email'] ?? null, $this->auditVia(), request()->ip(), $exception->secondsUntilAvailable);

        return parent::getRateLimitedNotification($exception);
    }

    /**
     * Runs right after Filament's rate limit and before any password check.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function getCredentialsFromFormData(#[SensitiveParameter] array $data): array
    {
        $email = (string) ($data['email'] ?? '');
        try {
            app(PasswordAuthGate::class)->assertPasswordLoginWith($email, (string) ($data['password'] ?? ''));
        } catch (SsoRequiredException $e) {
            app(BlockedPasswordFlow::class)->login($email, $e, $this->auditVia());
            $this->userUndertakingMultiFactorAuthentication = null;

            throw ValidationException::withMessages(['data.email' => $this->passwordLoginRefused($e)]);
        }

        return parent::getCredentialsFromFormData($data);
    }
}
