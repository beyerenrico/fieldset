<?php

declare(strict_types=1);

namespace App\Domain\Identity\Sso\Login;

use App\Domain\Audit\AuditLogger;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Sso\Exceptions\SsoRequiredException;
use Illuminate\Validation\ValidationException;

/**
 * Audits a password flow refused by the PasswordAuthGate and builds the client error (ADR 0036, M7 contract):
 * `auth.password_login_blocked` / `auth.password_reset_blocked` (subject: the enforcing provider, so the
 * entry belongs to its organization; `via` names the flow) and a 422 `{message, errors: {sso_required:
 * [text]}}`. Shared by the Fortify login and reset, the invitation and (B) the Filament pages.
 */
final readonly class BlockedPasswordFlow
{
    public const string ERROR_KEY = 'sso_required';

    public function __construct(private AuditLogger $audit) {}

    public function login(string $email, SsoRequiredException $e, string $via): ValidationException
    {
        $this->record('auth.password_login_blocked', $email, $e, $via);

        return self::exception();
    }

    public function reset(string $email, SsoRequiredException $e, string $via): ValidationException
    {
        $this->record('auth.password_reset_blocked', $email, $e, $via);

        return self::exception();
    }

    public static function exception(): ValidationException
    {
        return ValidationException::withMessages([self::ERROR_KEY => __('sso.errors.sso_required')]);
    }

    private function record(string $event, string $email, SsoRequiredException $e, string $via): void
    {
        $email = EmailDomain::normalizeEmail($email);
        $this->audit->record($event, $e->discovery->provider, [
            'email' => $email,
            'via' => $via,
            'provider' => $e->discovery->provider?->uuid,
        ], User::query()->where('email', $email)->first());
    }
}
