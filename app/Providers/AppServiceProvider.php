<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Audit\Listeners\LogAuthEvents;
use App\Domain\Documents\Events\RenditionCreated;
use App\Domain\Documents\Renditions\QueueRenditionOnSubmit;
use App\Domain\Identity\Enums\Permission;
use App\Domain\Identity\Models\Group;
use App\Domain\Identity\Models\Membership;
use App\Domain\Identity\Models\Organization;
use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Support\OrganizationPermissions;
use App\Domain\Identity\Tenancy\TenantContext;
use App\Domain\Mail\Listeners\SendWaitingDocuments;
use App\Domain\Submissions\Events\SubmissionSubmitted;
use App\Domain\Submissions\Models\IdempotencyKey;
use App\Domain\Submissions\Models\NumberSequence;
use App\Domain\Submissions\Models\SubmissionEvent;
use App\Policies\GroupPolicy;
use App\Policies\InternalRecordPolicy;
use App\Policies\MembershipPolicy;
use App\Policies\OrganizationPolicy;
use App\Policies\RolePolicy;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
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
        Event::listen(SubmissionSubmitted::class, QueueRenditionOnSubmit::class);
        Event::listen(RenditionCreated::class, SendWaitingDocuments::class);

        Gate::policy(Organization::class, OrganizationPolicy::class);
        Gate::policy(Group::class, GroupPolicy::class);
        Gate::policy(Membership::class, MembershipPolicy::class);
        Gate::policy(Role::class, RolePolicy::class);
        // Other models use auto-discovered App\Policies\{Model}Policy; bookkeeping records share one deny-all policy.
        Gate::policy(SubmissionEvent::class, InternalRecordPolicy::class);
        Gate::policy(NumberSequence::class, InternalRecordPolicy::class);
        Gate::policy(IdempotencyKey::class, InternalRecordPolicy::class);

        // OpenAPI docs (/docs/api, M2-T11): platform admins and organization admins (members.manage).
        Gate::define('viewApiDocs', fn (?User $user = null): bool => $user instanceof User && $user->isActive() && (
            in_array($user->email, config('app.platform_admins', []), true)
            || $user->activeOrganizations()->get()->contains(fn ($org): bool => $user->hasPermissionIn($org, Permission::MembersManage))
        ));

        RateLimiter::for('api', function (Request $request): Limit {
            $user = $request->user();

            return Limit::perMinute((int) config('app.api_rate_limit', 240))
                ->by($user !== null ? 'user:'.$user->getAuthIdentifier() : 'ip:'.$request->ip());
        });
    }
}
