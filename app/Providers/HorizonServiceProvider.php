<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Identity\Models\User;
use Illuminate\Support\Facades\Gate;
use Laravel\Horizon\HorizonApplicationServiceProvider;

class HorizonServiceProvider extends HorizonApplicationServiceProvider
{
    /**
     * Horizon is a platform-operator tool: only emails listed in PLATFORM_ADMIN_EMAILS may open it.
     */
    protected function gate(): void
    {
        Gate::define('viewHorizon', fn (?User $user = null): bool => $user instanceof User
            && $user->isActive()
            && in_array($user->email, config('app.platform_admins', []), true));
    }
}
