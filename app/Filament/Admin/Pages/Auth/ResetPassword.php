<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages\Auth;

use App\Domain\Identity\LoginLinks\LoginLinkHandoff;
use App\Domain\Identity\LoginLinks\LoginLinkKind;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Sso\Contracts\PasswordAuthGate;
use App\Domain\Identity\Sso\Exceptions\SsoRequiredException;
use App\Domain\Identity\Sso\Login\BlockedPasswordFlow;
use Filament\Auth\Http\Responses\Contracts\PasswordResetResponse;
use Filament\Auth\Pages\PasswordReset\ResetPassword as BaseResetPassword;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use SensitiveParameter;

/**
 * Filament's reset page with the SSO gate (ADR 0036, Enforcement): a reset link issued before enforcement
 * was switched on can no longer set a password for an email whose domain enforces SSO. Checked after
 * Filament's rate limits and before the token; the refusal is audited.
 *
 * It is also the second step of the owner's first sign-in link (ADR 0056): the landing page leaves the reset
 * token in the session and sends a signed URL with the email only, so the token never appears in a URL. After
 * the password is set the owner is signed in (unless MFA is already enrolled: then the normal login with its
 * challenge) and lands on the profile page, which offers MFA. An account without an organization (an operator made by
 * `fieldset config set operator-email`) goes to the /platform sign-in instead.
 */
class ResetPassword extends BaseResetPassword
{
    #[Locked]
    public bool $fromOwnerLink = false;

    public function mount(?string $email = null, #[SensitiveParameter] ?string $token = null): void
    {
        parent::mount($email, $token);

        if (blank($this->token)) {
            $this->token = app(LoginLinkHandoff::class)->pullResetToken($this->email);
            $this->fromOwnerLink = $this->token !== null;
        }
    }

    public function resetPassword(): ?PasswordResetResponse
    {
        $response = parent::resetPassword();
        if (! $response instanceof PasswordResetResponse || ! $this->fromOwnerLink) {
            return $response;
        }

        $user = User::query()->where('email', (string) $this->email)->first();
        if (! $user instanceof User || LoginLinkHandoff::requiresMfa($user)) {
            return $response;
        }
        if (! $user->activeOrganizations()->exists()) {
            // An operator made by `fieldset config set operator-email` has no organization and no /admin page (ADR 0080):
            // the operator panel's own sign-in, with its MFA set-up, comes next.
            Notification::make()->success()->persistent()
                ->title(__('login_link.operator.ready_title'))->body(__('login_link.operator.ready_body'))->send();

            return new OwnerSignedInResponse(route('filament.platform.auth.login'));
        }
        app(LoginLinkHandoff::class)->signIn($user, LoginLinkKind::Owner);
        Notification::make()->success()->persistent()
            ->title(__('login_link.mfa.offer_title'))->body(__('login_link.mfa.offer_body'))->send();

        return new OwnerSignedInResponse((string) (Filament::getProfileUrl() ?? Filament::getUrl()));
    }

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
