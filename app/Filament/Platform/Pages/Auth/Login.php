<?php

declare(strict_types=1);

namespace App\Filament\Platform\Pages\Auth;

use App\Domain\Identity\Models\User;
use App\Domain\Platform\Auth\PlatformSession;
use Filament\Auth\Http\Responses\Contracts\LoginResponse;
use Filament\Auth\Pages\Login as BaseLogin;
use Filament\Facades\Filament;

/**
 * /platform/login: Filament's password login with its MFA challenge, 5 attempts/min per IP (ADR 0041).
 * A successful sign-in marks the session for /platform (PlatformSession). A session that is signed in
 * without that mark (e.g. through an organization's SSO on /admin) sees the form instead of a redirect.
 */
final class Login extends BaseLogin
{
    public function mount(): void
    {
        if (Filament::auth()->check() && app(PlatformSession::class)->isValid(Filament::auth()->user())) {
            redirect()->intended(Filament::getUrl());

            return;
        }

        $this->form->fill();
    }

    public function authenticate(): ?LoginResponse
    {
        $response = parent::authenticate();
        $user = Filament::auth()->user();
        if ($response instanceof LoginResponse && $user instanceof User) {
            app(PlatformSession::class)->mark($user);
        }

        return $response;
    }
}
