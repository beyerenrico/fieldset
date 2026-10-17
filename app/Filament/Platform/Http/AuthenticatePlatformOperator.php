<?php

declare(strict_types=1);

namespace App\Filament\Platform\Http;

use App\Domain\Platform\Auth\PlatformSession;
use Filament\Facades\Filament;
use Filament\Http\Middleware\Authenticate;
use Illuminate\Auth\AuthenticationException;

/**
 * /platform authentication (ADR 0041): Filament's check (guests → /platform/login, non-operators 403 via
 * User::canAccessPanel), then the session must have signed in through /platform/login (PlatformSession).
 */
final class AuthenticatePlatformOperator extends Authenticate
{
    /** @param  array<string>  $guards */
    protected function authenticate($request, array $guards): void
    {
        parent::authenticate($request, $guards);

        if (! app(PlatformSession::class)->isValid(Filament::auth()->user())) {
            throw new AuthenticationException('Platform sign-in required.', $guards, Filament::getLoginUrl());
        }
    }
}
