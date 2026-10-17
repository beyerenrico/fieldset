<?php

declare(strict_types=1);

namespace App\Http\Controllers\Sso;

use App\Domain\Identity\Models\IdentityProvider;
use App\Domain\Identity\Sso\Contracts\HomeRealmDiscovery;
use App\Domain\Identity\Sso\Enums\SsoClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * POST /auth/sso/discover {email, client?=app} (throttled; ADR 0036, M7 Field App contract): plain JSON, not
 * the API envelope — `{mode: "password"}` or `{mode: "sso", redirect_url, organization, enforced}`. Decided
 * by the email's domain only; it never reveals whether an account exists.
 */
final class DiscoverController
{
    public function __invoke(Request $request, HomeRealmDiscovery $discovery): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'string', 'email', 'max:254'],
            'client' => ['nullable', Rule::enum(SsoClient::class)],
        ]);
        $client = SsoClient::tryFrom(is_string($data['client'] ?? null) ? $data['client'] : '') ?? SsoClient::App;
        $result = $discovery->discover((string) $data['email'], $client);

        if (! $result->isSso() || ! $result->provider instanceof IdentityProvider) {
            return response()->json(['mode' => 'password']);
        }

        return response()->json([
            'mode' => 'sso',
            'redirect_url' => $result->redirectUrl,
            'organization' => $result->provider->organization->name,
            'enforced' => $result->enforced,
        ]);
    }
}
