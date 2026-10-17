<?php

declare(strict_types=1);

namespace App\Http\Controllers\Sso;

use App\Domain\Identity\Enums\Permission;
use App\Domain\Identity\Models\IdentityProvider;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Sso\Enums\IdentityProviderStatus;
use App\Domain\Identity\Sso\Enums\IdentityProviderType;
use App\Domain\Identity\Sso\Enums\SsoClient;
use App\Domain\Identity\Sso\Enums\SsoRefusal;
use App\Domain\Identity\Sso\Login\DomainProviderLookup;
use App\Domain\Identity\Sso\Login\FinishSsoLogin;
use App\Domain\Identity\Sso\Login\SsoFlow;
use App\Domain\Identity\Sso\Login\SsoFlowStore;
use App\Domain\Identity\Sso\Login\SsoLoginResponder;
use App\Domain\Identity\Sso\Providers\DynamicProviderFactory;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * GET /auth/sso/{provider}/redirect?client=admin|app[&login_hint=…][&test=1] (ADR 0036): starts an OIDC
 * (state, nonce, PKCE S256, response_mode=query; prompt=login with force_reauth or in test mode) or SAML
 * login (RelayState = one-time flow id bound to the AuthnRequest id). The return target is the client enum.
 * Test mode needs members.manage in the provider's organization, works for draft providers, signs nobody
 * in and returns the claims to the admin page that started it.
 */
final class RedirectController
{
    public function __invoke(
        Request $request,
        IdentityProvider $provider,
        DynamicProviderFactory $factory,
        SsoFlowStore $flows,
        FinishSsoLogin $finish,
        SsoLoginResponder $respond,
    ): Response {
        $client = SsoClient::tryFrom((string) $request->query('client', SsoClient::App->value));
        abort_if($client === null, 404);
        $test = $request->boolean('test');

        if ($test) {
            $this->authorizeTest($request, $provider);
        } elseif (! DomainProviderLookup::takesLogins($provider)) {
            return $respond->failure($client, SsoRefusal::ProviderUnavailable);
        }
        abort_if($test && $provider->status === IdentityProviderStatus::Disabled, 409);

        $flow = $flows->start($request, $provider, $client, $test, $test ? $this->adminReturnUrl() : null);

        try {
            return $provider->type === IdentityProviderType::Oidc
                ? $this->oidc($factory, $provider, $test, $this->loginHint($request))
                : $this->saml($factory, $flows, $provider, $flow, $test);
        } catch (Throwable $e) {
            $flows->pull($request);

            return $finish->failed($provider, $flow, $e);
        }
    }

    private function oidc(DynamicProviderFactory $factory, IdentityProvider $provider, bool $test, ?string $loginHint): Response
    {
        return $factory->oidc($provider)->with(array_filter([
            'response_mode' => 'query',
            'login_hint' => $loginHint,
            'prompt' => $provider->force_reauth || $test ? 'login' : null,
        ]))->redirect();
    }

    private function saml(DynamicProviderFactory $factory, SsoFlowStore $flows, IdentityProvider $provider, SsoFlow $flow, bool $test): Response
    {
        $saml = $factory->saml($provider)->forcingAuthentication($provider->force_reauth || $test);
        $saml->with(['RelayState' => $flow->id]);
        $response = $saml->redirect();
        $flows->rememberRelay($flow, (string) $saml->lastRequestId());

        return $response;
    }

    private function authorizeTest(Request $request, IdentityProvider $provider): void
    {
        $user = $request->user();
        abort_unless($user instanceof User && $user->hasPermissionIn($provider->organization_id, Permission::MembersManage), 403);
    }

    /** The admin page that started the test (same origin, under /admin), else the panel. */
    private function adminReturnUrl(): string
    {
        $previous = url()->previous();

        return str_starts_with($previous, url('/admin').'/') ? $previous : url('/admin');
    }

    private function loginHint(Request $request): ?string
    {
        $hint = $request->query('login_hint');

        return is_string($hint) && filter_var($hint, FILTER_VALIDATE_EMAIL) !== false && strlen($hint) <= 254 ? $hint : null;
    }
}
