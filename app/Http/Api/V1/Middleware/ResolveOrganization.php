<?php

declare(strict_types=1);

namespace App\Http\Api\V1\Middleware;

use App\Domain\Identity\Models\Organization;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Support\LocaleResolver;
use App\Domain\Identity\Tenancy\TenantContext;
use App\Http\Api\ApiException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Every tenant-scoped API request names its organization in X-Organization-Id, validated against
 * the user's active memberships (BUILD_PROMPT §6.2, §10).
 */
final readonly class ResolveOrganization
{
    public function __construct(
        private TenantContext $tenant,
        private LocaleResolver $locales,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $header = (string) $request->headers->get('X-Organization-Id', '');

        if ($header === '' || preg_match('/^\d{1,18}$/', $header) !== 1) {
            throw new ApiException('organization_required', __('api.organization_required'), 400);
        }

        $user = $request->user();

        if (! $user instanceof User || ! $user->isActive()) {
            throw new ApiException('unauthenticated', __('api.unauthenticated'), 401);
        }

        $organization = $user->activeOrganizations()->whereKey((int) $header)->first();

        if (! $organization instanceof Organization) {
            throw new ApiException('organization_forbidden', __('api.organization_forbidden'), 403);
        }

        $this->tenant->set($organization);
        $this->locales->apply($request);

        return $next($request);
    }
}
