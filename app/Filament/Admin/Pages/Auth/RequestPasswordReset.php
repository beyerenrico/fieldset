<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages\Auth;

use App\Domain\Identity\Sso\Contracts\PasswordAuthGate;
use App\Domain\Identity\Sso\Exceptions\SsoRequiredException;
use App\Domain\Identity\Sso\Login\BlockedPasswordFlow;
use Filament\Auth\Pages\PasswordReset\RequestPasswordReset as BaseRequestPasswordReset;
use Illuminate\Validation\ValidationException;

/**
 * Filament's "forgot password" page with the SSO gate (ADR 0036, Enforcement): Filament notifies the user
 * directly (not through User::sendPasswordResetNotification), so an email whose domain enforces SSO is
 * refused here, after Filament's rate limit and before a link is sent; the refusal is audited.
 */
class RequestPasswordReset extends BaseRequestPasswordReset
{
    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function getCredentialsFromFormData(array $data): array
    {
        $email = (string) ($data['email'] ?? '');
        try {
            app(PasswordAuthGate::class)->assertPasswordReset($email);
        } catch (SsoRequiredException $e) {
            app(BlockedPasswordFlow::class)->reset($email, $e, 'admin_forgot');

            throw ValidationException::withMessages(['data.email' => __('sso_admin.reset.sso_required')]);
        }

        return parent::getCredentialsFromFormData($data);
    }
}
