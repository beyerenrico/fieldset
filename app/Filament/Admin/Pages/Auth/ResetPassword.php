<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages\Auth;

use App\Domain\Identity\Sso\Contracts\PasswordAuthGate;
use App\Domain\Identity\Sso\Exceptions\SsoRequiredException;
use App\Domain\Identity\Sso\Login\BlockedPasswordFlow;
use Filament\Auth\Pages\PasswordReset\ResetPassword as BaseResetPassword;
use Filament\Notifications\Notification;
use Illuminate\Validation\ValidationException;
use SensitiveParameter;

/**
 * Filament's reset page with the SSO gate (ADR 0036, Enforcement): a reset link issued before enforcement
 * was switched on can no longer set a password for an email whose domain enforces SSO. Checked after
 * Filament's rate limits and before the token; the refusal is audited.
 */
class ResetPassword extends BaseResetPassword
{
    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function getCredentialsFromFormData(#[SensitiveParameter] array $data): array
    {
        $email = (string) ($data['email'] ?? '');
        try {
            app(PasswordAuthGate::class)->assertPasswordReset($email);
        } catch (SsoRequiredException $e) {
            app(BlockedPasswordFlow::class)->reset($email, $e, 'admin_reset');
            Notification::make()->danger()->title(__('sso_admin.reset.sso_required'))->send();

            throw ValidationException::withMessages(['email' => __('sso_admin.reset.sso_required')]);
        }

        return parent::getCredentialsFromFormData($data);
    }
}
