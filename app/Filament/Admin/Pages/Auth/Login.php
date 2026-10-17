<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages\Auth;

use App\Domain\Identity\Sso\Contracts\HomeRealmDiscovery;
use App\Domain\Identity\Sso\Contracts\PasswordAuthGate;
use App\Domain\Identity\Sso\Data\DiscoveryResult;
use App\Domain\Identity\Sso\Enums\SsoClient;
use App\Domain\Identity\Sso\Enums\SsoRefusal;
use App\Domain\Identity\Sso\Exceptions\SsoRequiredException;
use App\Domain\Identity\Sso\Login\BlockedPasswordFlow;
use DanHarrin\LivewireRateLimiting\Exceptions\TooManyRequestsException;
use Filament\Actions\Action;
use Filament\Auth\Http\Responses\Contracts\LoginResponse;
use Filament\Auth\Pages\Login as BaseLogin;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use SensitiveParameter;
use Throwable;

/**
 * Email-first admin login (BUILD_PROMPT §8.7, ADR 0036, Home-realm discovery):
 * 1. The email step asks only for the address; "Continue" runs home-realm discovery (domain level, never
 *    revealing whether a user exists). A verified domain with SSO redirects to the identity provider.
 * 2. Otherwise the password step shows password, "remember me" and, after it, Filament's MFA challenge.
 * "Sign in with a password" skips discovery (break-glass Owner, organizations that do not enforce SSO).
 * Before any password check the PasswordAuthGate may refuse an enforced domain: the error sits on the
 * email field with a link to SSO, and the refusal is audited. Filament's throttling (5/min per IP for
 * sign-in, 10/min for discovery) and its MFA flow stay as they are.
 */
class Login extends BaseLogin
{
    public const string STEP_EMAIL = 'email';

    public const string STEP_PASSWORD = 'password';

    #[Locked]
    public string $step = self::STEP_EMAIL;

    /** SSO sign-in link shown after the gate refused a password login. */
    #[Locked]
    public ?string $ssoUrl = null;

    public function mount(): void
    {
        parent::mount();

        $refusal = SsoRefusal::tryFrom((string) request()->query('sso_error'));
        if ($refusal instanceof SsoRefusal) {
            Notification::make()->danger()->title(__('sso_admin.login.refused.'.$refusal->value))->persistent()->send();
        }
    }

    public function form(Schema $schema): Schema
    {
        $passwordStep = fn (): bool => $this->step === self::STEP_PASSWORD;

        return $schema->components([
            $this->getEmailFormComponent(),
            $this->getPasswordFormComponent()->visible($passwordStep),
            $this->getRememberFormComponent()->visible($passwordStep),
            Actions::make([
                Action::make('ssoLink')->label(__('sso_admin.login.sso_link'))->icon(Heroicon::OutlinedArrowRightEndOnRectangle)
                    ->link()->url(fn (): ?string => $this->ssoUrl),
            ])->visible(fn (): bool => filled($this->ssoUrl)),
        ]);
    }

    /** Email step: home-realm discovery, then the identity provider or the password step. */
    public function continueWithEmail(): void
    {
        try {
            $this->rateLimit(10);
        } catch (TooManyRequestsException $exception) {
            $this->getRateLimitedNotification($exception)?->send();

            return;
        }

        $email = (string) ($this->form->getState()['email'] ?? '');
        $this->ssoUrl = null;
        $discovery = $this->discover($email);
        if ($discovery->isSso()) {
            $this->redirect((string) $discovery->redirectUrl);

            return;
        }

        $this->step = self::STEP_PASSWORD;
    }

    /** Break-glass and organizations without SSO: straight to the password step. */
    public function usePassword(): void
    {
        $this->step = self::STEP_PASSWORD;
        $this->ssoUrl = null;
    }

    public function useOtherEmail(): void
    {
        $this->step = self::STEP_EMAIL;
        $this->ssoUrl = null;
        $this->userUndertakingMultiFactorAuthentication = null;
        $this->data = ['email' => $this->data['email'] ?? null, 'password' => null, 'remember' => $this->data['remember'] ?? false];
        $this->resetErrorBag();
    }

    public function authenticate(): ?LoginResponse
    {
        if ($this->step !== self::STEP_PASSWORD) {
            $this->continueWithEmail();

            return null;
        }

        return parent::authenticate();
    }

    public function getFormContentComponent(): Component
    {
        return Form::make([EmbeddedSchema::make('form')])
            ->id('form')
            ->livewireSubmitHandler(fn (): string => $this->step === self::STEP_PASSWORD ? 'authenticate' : 'continueWithEmail')
            ->footer([
                Actions::make($this->getFormActions())
                    ->alignment($this->getFormActionsAlignment())
                    ->fullWidth($this->hasFullWidthFormActions())
                    ->key('form-actions'),
                Actions::make($this->getStepLinkActions())->alignment($this->getFormActionsAlignment())->key('step-links'),
            ])
            ->visible(fn (): bool => blank($this->userUndertakingMultiFactorAuthentication));
    }

    public function getSubheading(): string|Htmlable|null
    {
        if ($this->step === self::STEP_PASSWORD && blank($this->userUndertakingMultiFactorAuthentication)) {
            return __('sso_admin.login.password_step');
        }

        return parent::getSubheading();
    }

    /** @return array<Action> */
    protected function getFormActions(): array
    {
        if ($this->step === self::STEP_PASSWORD) {
            return [$this->getAuthenticateFormAction()];
        }

        return [Action::make('continueWithEmail')->label(__('sso_admin.login.continue'))->submit('continueWithEmail')];
    }

    /** @return array<Action> */
    protected function getStepLinkActions(): array
    {
        return $this->step === self::STEP_PASSWORD
            ? [Action::make('useOtherEmail')->label(__('sso_admin.login.use_other_email'))->link()->action('useOtherEmail')]
            : [Action::make('usePassword')->label(__('sso_admin.login.use_password'))->link()->action('usePassword')];
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
            app(PasswordAuthGate::class)->assertPasswordLogin($email);
        } catch (SsoRequiredException $e) {
            app(BlockedPasswordFlow::class)->login($email, $e, 'admin');
            $this->userUndertakingMultiFactorAuthentication = null;
            $this->ssoUrl = $e->discovery->provider?->redirectUrl(SsoClient::Admin, $email);

            throw ValidationException::withMessages(['data.email' => __('sso_admin.login.sso_required')]);
        }

        return parent::getCredentialsFromFormData($data);
    }

    private function discover(string $email): DiscoveryResult
    {
        try {
            return app(HomeRealmDiscovery::class)->discover($email, SsoClient::Admin);
        } catch (Throwable $e) {
            report($e); // discovery trouble never blocks the password step

            return DiscoveryResult::password();
        }
    }
}
