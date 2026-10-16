<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Identity\Models\Organization;
use App\Domain\Identity\Tenancy\TenantContext;
use Closure;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Copies Filament's resolved tenant into the TenantContext. Registered as persistent tenant
 * middleware so Livewire follow-up requests keep the tenant (BUILD_PROMPT §6.2).
 */
final readonly class SetTenantFromFilament
{
    public function __construct(private TenantContext $tenant) {}

    public function handle(Request $request, Closure $next): Response
    {
        $organization = Filament::getTenant();

        if ($organization instanceof Organization) {
            $this->tenant->set($organization);
        }

        return $next($request);
    }
}
