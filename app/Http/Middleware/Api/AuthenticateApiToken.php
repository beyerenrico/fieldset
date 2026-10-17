<?php

declare(strict_types=1);

namespace App\Http\Middleware\Api;

use App\Domain\Identity\Models\User;
use App\Domain\Identity\Tokens\Actions\RecordFirstTokenUse;
use App\Domain\Identity\Tokens\RequestToken;
use App\Http\Api\ApiException;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * Admits integration tokens to /api/v1 (ADR 0043); Field App sessions pass untouched. A token must be bound to
 * an organization its (active) user still belongs to, and the route must declare its abilities with
 * RequireTokenAbility, so a route added without a declaration stays closed to tokens. The token's
 * organization stands in for a missing X-Organization-Id; a different header is refused by
 * ResolveOrganization. The first use of a token is audited.
 */
final readonly class AuthenticateApiToken
{
    public function __construct(private RecordFirstTokenUse $firstUse) {}

    public function handle(Request $request, Closure $next): Response
    {
        $token = RequestToken::of($request->user());
        if (! $token instanceof PersonalAccessToken) {
            return $next($request);
        }

        $user = $request->user();
        if (! $user instanceof User || ! $user->isActive()) {
            throw new ApiException('unauthenticated', __('api.unauthenticated'), 401);
        }

        $organizationId = $token->getAttribute('organization_id');
        if ($organizationId === null) {
            throw new ApiException('token_unbound', __('api_tokens.errors.token_unbound'), 403);
        }
        if (! $user->isMemberOf((int) $organizationId)) {
            throw new ApiException('organization_forbidden', __('api.organization_forbidden'), 403);
        }

        $this->firstUse->handle($token);

        if (! $this->declaresAbilities($request)) {
            throw new ApiException('session_required', __('api_tokens.errors.session_required'), 403);
        }
        if ((string) $request->headers->get('X-Organization-Id', '') === '') {
            $request->headers->set('X-Organization-Id', (string) $organizationId);
        }

        return $next($request);
    }

    private function declaresAbilities(Request $request): bool
    {
        $middleware = $request->route()?->gatherMiddleware() ?? [];

        return array_any($middleware, static fn (mixed $m): bool => is_string($m) && Str::before($m, ':') === RequireTokenAbility::class);
    }
}
