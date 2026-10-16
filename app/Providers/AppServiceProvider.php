<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Audit\Listeners\LogAuthEvents;
use App\Domain\Identity\Support\OrganizationPermissions;
use App\Domain\Identity\Tenancy\TenantContext;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Scoped: fresh per request and per queued job (BUILD_PROMPT §6.2).
        $this->app->scoped(TenantContext::class);
        $this->app->scoped(OrganizationPermissions::class);
    }

    public function boot(): void
    {
        Model::shouldBeStrict(! $this->app->isProduction());

        // The framework hydrates Context on JobProcessing before this listener runs (its provider
        // boots first). Re-sync the spatie team id so a worker never keeps the previous job's team.
        Event::listen(JobProcessing::class, function (): void {
            app(TenantContext::class)->syncPermissionTeam();
        });

        Event::subscribe(LogAuthEvents::class);

        RateLimiter::for('api', function (Request $request): Limit {
            $user = $request->user();

            return Limit::perMinute((int) config('app.api_rate_limit', 240))
                ->by($user !== null ? 'user:'.$user->getAuthIdentifier() : 'ip:'.$request->ip());
        });
    }
}
