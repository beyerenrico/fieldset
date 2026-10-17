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
 * GET /auth/sso/saml/complete?ticket=… (ADR 0036, SAML): redeems the ACS ticket only for the session whose
 * flow it was issued for (binding check against login CSRF), then completes the login like the OIDC callback.
 */
final class SamlCompleteController
{
    public function __invoke(Request $request, SsoFlowStore $flows, FinishSsoLogin $finish, SsoLoginResponder $respond): RedirectResponse
    {
        $flow = $flows->pull($request);
        $ticketParameter = $request->query('ticket');
        if (! is_string($ticketParameter) || $ticketParameter === '') {
            // The ACS refused the RelayState and audited it with the provider.
            return $respond->failure($flow->client ?? SsoClient::App, SsoRefusal::SsoFailed);
        }

        $ticket = $flows->redeemTicket($ticketParameter);
        $provider = $ticket === null ? null : IdentityProvider::query()->withoutTenant()->where('uuid', $ticket['provider'])->first();
        if (! $flow instanceof SsoFlow || $ticket === null || ! $provider instanceof IdentityProvider
            || ! hash_equals($flow->id, $ticket['flow']) || ! $flow->belongsTo($provider)) {
            return $finish->flowRejected($flow->client ?? SsoClient::App, 'saml_ticket_binding', $provider);
        }

        return $ticket['result'] instanceof SsoRefusal
            ? $finish->failureResponse($flow, $ticket['result'])
            : $finish->complete($request, $provider, $flow, $ticket['result']);
    }
}
