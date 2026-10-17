<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Identity\Models\User;
use App\Domain\Platform\Auth\PlatformSession;
use App\Domain\Platform\PlatformAdmins;
use Illuminate\Support\Facades\Gate;
use Laravel\Horizon\HorizonApplicationServiceProvider;

class HorizonServiceProvider extends HorizonApplicationServiceProvider
{
    /**
     * Horizon is a platform-operator tool (ADR 0068, amending ADR 0041): an active operator whose session signed
     * in on /platform (password + MFA, the PlatformSession mark, not older than platform.session_hours). An
     * /admin session, an organization's SSO or a one-time sign-in link never opens it; PLATFORM_ADMIN_EMAILS no
     * longer does either.
     */
    protected function gate(): void
    {
        Gate::define('viewHorizon', fn (?User $user = null): bool => $user instanceof User
            && app(PlatformAdmins::class)->check($user)
            && app(PlatformSession::class)->isValid($user));
    }
}
