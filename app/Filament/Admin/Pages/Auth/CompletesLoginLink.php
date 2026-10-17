<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages\Auth;

use App\Domain\Audit\AuditLogger;
use App\Domain\Identity\LoginLinks\LoginLinkHandoff;
use App\Domain\Identity\LoginLinks\LoginLinkKind;
use App\Domain\Identity\Models\User;
use DanHarrin\LivewireRateLimiting\Exceptions\TooManyRequestsException;
use Filament\Auth\Http\Responses\Contracts\LoginResponse;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;

/**
 * The admin login's part of a one-time sign-in link (ADR 0056): after the landing page consumed a link of an
 * admin with MFA, this session carries a short-lived mark (LoginLinkHandoff). The login then skips the
 * email and password steps and shows Filament's MFA challenge for that user, with Filament's challenge rate
 * limit; only a correct code signs the session in. A wrong code is audited like any other.
 */
trait CompletesLoginLink
{
    #[Locked]
    public bool $loginLinkChallenge = false;

    protected function resumeLoginLink(): void
    {
        $user = app(LoginLinkHandoff::class)->pendingMfaUser();
        $challenge = $this->getMultiFactorChallenge();
        $provider = $user instanceof User ? $challenge->getFirstEnabledProvider($user) : null;
        if (! $user instanceof User || $provider === null) {
            return;
        }

        $this->loginLinkChallenge = true;
        $this->step = self::STEP_PASSWORD;
        $this->data = ['email' => $user->email, 'password' => null, 'remember' => false];
        $this->userUndertakingMultiFactorAuthentication = encrypt([
            'identifier' => $user->getAuthIdentifier(),
            'userKey' => Filament::getUserScopedAuthIdentifier($user),
        ]);
        $challenge->beforeChallenge($user, $provider);
        $this->multiFactorChallengeForm->fill();
    }

    protected function completeLoginLink(): ?LoginResponse
    {
        try {
            $this->rateLimit(5);
        } catch (TooManyRequestsException $exception) {
            $this->getRateLimitedNotification($exception)?->send();

            return null;
        }

        $handoff = app(LoginLinkHandoff::class);
        $user = $handoff->pendingMfaUser();
        $challenged = $this->getUserUndertakingMultiFactorAuthentication();
        if (! $user instanceof User || ! $challenged instanceof User || ! $user->is($challenged) || ! $this->isUserAllowedToAccessPanel($user)) {
            $this->abandonLoginLink();

            return null;
        }
        if ($this->isMultiFactorChallengeRateLimited($user)) {
            return null;
        }

        $this->cacheSchema('multiFactorChallengeForm', null);
        try {
            $this->multiFactorChallengeForm->validate();
        } catch (ValidationException $e) {
            app(AuditLogger::class)->record('auth.mfa_failed', $user, ['via' => 'login_link'], $user);

            throw $e;
        }

        $handoff->signIn($user, LoginLinkKind::Admin);

        return app(LoginResponse::class);
    }

    /** The mark expired or the account changed: back to the normal login, with a hint to request a new link. */
    protected function abandonLoginLink(): void
    {
        app(LoginLinkHandoff::class)->forgetMfa();
        $this->loginLinkChallenge = false;
        $this->userUndertakingMultiFactorAuthentication = null;
        $this->step = self::STEP_EMAIL;
        Notification::make()->danger()->persistent()->title(__('login_link.mfa.expired'))->send();
    }
}
