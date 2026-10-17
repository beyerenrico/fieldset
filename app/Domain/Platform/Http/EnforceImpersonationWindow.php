<?php

declare(strict_types=1);

namespace App\Domain\Platform\Http;

use App\Domain\Platform\Impersonation\EndImpersonation;
use App\Domain\Platform\Impersonation\Impersonation;
use App\Domain\Platform\Impersonation\ImpersonationStore;
use App\Http\Api\ApiResponse;
use Closure;
use Filament\Facades\Filament;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Enforces an impersonation's limits on every request (ADR 0041): web and API groups as middleware,
 * Filament panel pages through a `Filament::serving()` hook (panels do not use the web group).
 * - past `expires_at` it ends (`ended_by=expired`), restores the operator and redirects to /platform;
 * - a session whose user is no longer the target only records the end (`ended_by=logout`);
 * - the API refuses impersonated sessions: impersonation is for the admin panel only, so no Field App
 *   device, offline store or sync ever runs as the target on the operator's machine.
 */
final readonly class EnforceImpersonationWindow
{
    public function __construct(private ImpersonationStore $store, private EndImpersonation $end, private AuthFactory $auth) {}

    public function handle(Request $request, Closure $next): Response
    {
        return $this->check($request) ?? $next($request);
    }

    public function check(Request $request): ?Response
    {
        if (! $request->hasSession()) {
            return null;
        }
        $impersonation = $this->store->current();
        if (! $impersonation instanceof Impersonation) {
            return null;
        }

        if ($this->auth->guard('web')->id() !== $impersonation->targetId) {
            $this->end->handle($impersonation, EndImpersonation::BY_LOGOUT);

            return null;
        }

        if ($impersonation->expired()) {
            $this->end->handle($impersonation, EndImpersonation::BY_EXPIRY);

            return $this->isApi($request)
                ? ApiResponse::error('impersonation_expired', __('platform.impersonation.expired'), 401)
                : redirect()->to($this->platformUrl())->with('platform.impersonation_notice', __('platform.impersonation.expired'));
        }

        return $this->isApi($request)
            ? ApiResponse::error('impersonation_api_blocked', __('platform.impersonation.api_blocked'), 403)
            : null;
    }

    private function isApi(Request $request): bool
    {
        return $request->is('api/*');
    }

    private function platformUrl(): string
    {
        return Filament::getPanel('platform')->getUrl() ?? url('/platform');
    }
}
