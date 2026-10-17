<?php

declare(strict_types=1);

namespace App\Domain\Platform\Http;

use App\Domain\Identity\Models\User;
use App\Domain\Platform\Impersonation\EndImpersonation;
use App\Domain\Platform\Impersonation\Impersonation;
use App\Domain\Platform\Impersonation\ImpersonationStore;
use Filament\Facades\Filament;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Http\RedirectResponse;

/**
 * POST /platform/impersonation/exit (CSRF-protected panel route outside the operator auth middleware,
 * because the signed-in user is the target): ends the impersonation and returns the operator to /platform.
 * 403 without an active impersonation, and when the signed-in user is not the impersonated target
 * (a leftover payload never restores the operator for someone else).
 */
final readonly class ExitImpersonationController
{
    public function __invoke(ImpersonationStore $store, EndImpersonation $end, AuthFactory $auth): RedirectResponse
    {
        $impersonation = $store->current();
        abort_if(! $impersonation instanceof Impersonation, 403);

        if ($auth->guard('web')->id() !== $impersonation->targetId) {
            $end->handle($impersonation, EndImpersonation::BY_LOGOUT);
            abort(403);
        }

        $operator = $end->handle($impersonation, $impersonation->expired() ? EndImpersonation::BY_EXPIRY : EndImpersonation::BY_OPERATOR);
        $panel = Filament::getPanel('platform');

        return $operator instanceof User
            ? redirect()->to($panel->getUrl() ?? url('/platform'))
            : redirect()->to($panel->getLoginUrl() ?? url('/platform/login'));
    }
}
