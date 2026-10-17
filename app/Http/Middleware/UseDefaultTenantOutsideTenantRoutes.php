<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Identity\Models\Organization;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Tenancy\TenantContext;
use Closure;
use Filament\Facades\Filament;
use Filament\Panel;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Admin pages without an organization in their URL (the panel's profile page) still render the full layout,
 * whose organization menu and "My data" link need a tenant: Filament 5.10.2's topbar fails with a TypeError
 * without one. They get the user's default organization. Registered as persistent auth middleware, so the
 * page's Livewire requests keep it; organization URLs keep theirs (SetTenantFromFilament).
 */
final readonly class UseDefaultTenantOutsideTenantRoutes
{
    public function __construct(private TenantContext $tenant) {}

    public function handle(Request $request, Closure $next): Response
    {
        $panel = Filament::getCurrentPanel();
        $user = Filament::auth()->user();

        if (! $panel instanceof Panel || ! $panel->hasTenancy() || ! $user instanceof User
            || Filament::getTenant() !== null || $request->route()?->hasParameter('tenant') === true) {
            return $next($request);
        }

        $organization = $user->getDefaultTenant($panel);
        if ($organization instanceof Organization) {
            Filament::setTenant($organization, isQuiet: true);
            $this->tenant->set($organization);
        }

        return $next($request);
    }
}
