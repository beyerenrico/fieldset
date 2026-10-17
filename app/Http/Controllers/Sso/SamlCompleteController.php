<?php

declare(strict_types=1);

namespace App\Http\Controllers\Sso;

use App\Domain\Identity\Models\IdentityProvider;
use App\Domain\Identity\Sso\Enums\SsoClient;
use App\Domain\Identity\Sso\Enums\SsoRefusal;
use App\Domain\Identity\Sso\Login\FinishSsoLogin;
use App\Domain\Identity\Sso\Login\SsoFlow;
use App\Domain\Identity\Sso\Login\SsoFlowStore;
use App\Domain\Identity\Sso\Login\SsoLoginResponder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * GET /auth/sso/saml/complete?ticket=… (ADR 0036, SAML): redeems the ACS ticket only in the session that holds
 * the flow it was issued for (binding check against login CSRF; the flow is used once), then completes the
 * login like the OIDC callback.
 */
final class SamlCompleteController
{
    public function __invoke(Request $request, SsoFlowStore $flows, FinishSsoLogin $finish, SsoLoginResponder $respond): RedirectResponse
    {
        $ticketParameter = $request->query('ticket');
        if (! is_string($ticketParameter) || $ticketParameter === '') {
            // The ACS refused the RelayState and audited it with the provider.
            return $respond->failure($flows->latest($request)->client ?? SsoClient::App, SsoRefusal::SsoFailed);
        }

        $ticket = $flows->redeemTicket($ticketParameter);
        $flow = $ticket === null ? null : $flows->pull($request, $ticket['flow']);
        $provider = $ticket === null ? null : IdentityProvider::query()->withoutTenant()->where('uuid', $ticket['provider'])->first();
        if (! $flow instanceof SsoFlow || $ticket === null || ! $provider instanceof IdentityProvider || ! $flow->belongsTo($provider)) {
            return $finish->flowRejected($flow->client ?? $flows->latest($request)->client ?? SsoClient::App, 'saml_ticket_binding', $provider);
        }

        return $ticket['result'] instanceof SsoRefusal
            ? $finish->failureResponse($flow, $ticket['result'])
            : $finish->complete($request, $provider, $flow, $ticket['result']);
    }
}
