<?php

declare(strict_types=1);

namespace App\Filament\Platform\Pages\Auth;

use App\Domain\Identity\Models\User;
use App\Domain\Identity\Sso\Exceptions\SsoRequiredException;
use App\Domain\Platform\Auth\PlatformSession;
use App\Filament\Admin\Pages\Auth\SecuresPasswordLogin;
use Filament\Auth\Http\Responses\Contracts\LoginResponse;
use Filament\Auth\Pages\Login as BaseLogin;
use Filament\Facades\Filament;

/**
 * /platform/login: Filament's password login with its MFA challenge, 5 attempts/min per IP (ADR 0041).
 * A successful sign-in marks the session for /platform (PlatformSession). A session that is signed in
 * without that mark (e.g. through an organization's SSO on /admin) sees the form instead of a redirect.
 * Like the admin login (SecuresPasswordLogin, via=platform): lockouts and wrong MFA codes are audited, and an
 * address whose domain enforces SSO is refused before any password check (its valid break-glass Owner excepted),
 * so the shared session never reaches /admin of an enforcing organization through a platform password sign-in.
 */
final class Login extends BaseLogin
{
    use SecuresPasswordLogin;

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
        $response = $this->authenticateAudited();
        $user = Filament::auth()->user();
        if ($response instanceof LoginResponse && $user instanceof User) {
            app(PlatformSession::class)->mark($user);
        }

        return $response;
    }

    protected function auditVia(): string
    {
        return 'platform';
    }

    /** No SSO link here: the operator panel accepts only its own password + MFA sign-in (ADR 0041). */
    protected function passwordLoginRefused(SsoRequiredException $e): string
    {
        return (string) __('platform.login.sso_required');
    }
}
