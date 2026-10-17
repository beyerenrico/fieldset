<?php

declare(strict_types=1);

namespace App\Domain\Platform\Http;

use App\Domain\Identity\Models\User;
use App\Domain\Platform\Impersonation\EndImpersonation;
use App\Domain\Platform\Impersonation\Impersonation;
use App\Domain\Platform\Impersonation\ImpersonationStore;
use App\Domain\Platform\PlatformAdmins;
use App\Http\Api\ApiResponse;
use Closure;
use Filament\Facades\Filament;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Enforces an impersonation's limits on every request (ADR 0041): web and API groups as middleware,
 * Filament panel pages through a `Filament::serving()` hook (panels do not use the web group).
 * - a session whose user is no longer the target only records the end (`ended_by=logout`);
 * - an operator who is no longer an active platform admin (grant revoked, deactivated, deleted) ends it at
 *   once (`ended_by=operator_revoked`): the session is signed out and goes to the admin sign-in;
 * - past `expires_at` it ends (`ended_by=expired`), restores the operator and redirects to /platform;
 * - the API refuses impersonated sessions: impersonation is for the admin panel only, so no Field App
 *   device, offline store or sync ever runs as the target on the operator's machine. The one exception is
 *   the signed PDF download (`documents.download`) that the admin "Download" redirects to: its signature,
 *   not the session, authorizes it.
 */
final readonly class EnforceImpersonationWindow
{
    public function __construct(
        private ImpersonationStore $store,
        private EndImpersonation $end,
        private AuthFactory $auth,
        private PlatformAdmins $admins,
    ) {}

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

        if (! $this->operatorStillAllowed($impersonation)) {
            $this->end->handle($impersonation, EndImpersonation::BY_OPERATOR_REVOKED);

            return $this->isApi($request)
                ? ApiResponse::error('impersonation_ended', __('platform.impersonation.operator_revoked'), 401)
                : redirect()->to($this->adminLoginUrl())->with('platform.impersonation_notice', __('platform.impersonation.operator_revoked'));
        }

        if ($impersonation->expired()) {
            $this->end->handle($impersonation, EndImpersonation::BY_EXPIRY);

            return $this->isApi($request)
                ? ApiResponse::error('impersonation_expired', __('platform.impersonation.expired'), 401)
                : redirect()->to($this->platformUrl())->with('platform.impersonation_notice', __('platform.impersonation.expired'));
        }

        return $this->isApi($request) && ! $this->isSignedDownload($request)
            ? ApiResponse::error('impersonation_api_blocked', __('platform.impersonation.api_blocked'), 403)
            : null;
    }

    /** Re-checked on every request (not memoized, ADR 0041): a revocation ends the impersonation at once. */
    private function operatorStillAllowed(Impersonation $impersonation): bool
    {
        $operator = User::query()->find($impersonation->operatorId);

        return $operator instanceof User && $this->admins->check($operator);
    }

    /** The signed rendition download: authorized by its signature and the user it names, not by the session. */
    private function isSignedDownload(Request $request): bool
    {
        return $request->routeIs('documents.download') && $request->hasValidSignature();
    }

    private function isApi(Request $request): bool
    {
        return $request->is('api/*');
    }

    private function adminLoginUrl(): string
    {
        return Filament::getPanel('admin')->getLoginUrl() ?? url('/admin/login');
    }

    private function platformUrl(): string
    {
        return Filament::getPanel('platform')->getUrl() ?? url('/platform');
    }
}
