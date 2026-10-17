<?php

declare(strict_types=1);

namespace App\Domain\Identity\Sso\Login;

use App\Domain\Identity\Models\IdentityProvider;
use App\Domain\Identity\Sso\Data\ExternalProfile;
use App\Domain\Identity\Sso\Enums\SsoClient;
use App\Domain\Identity\Sso\Enums\SsoRefusal;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;

/**
 * Login flow state (ADR 0036). OIDC: the flow lives in the session next to the package's state, nonce and
 * PKCE verifier and is pulled once by the callback. SAML: the RelayState is a one-time flow id whose cache
 * entry binds the IdP's POST to the AuthnRequest id; the session-less ACS hands the validated result over in
 * an encrypted one-time ticket, redeemed by sso.saml.complete only for the session that started the flow.
 * Cache values are plain arrays/strings (LEARNINGS #39).
 */
final class SsoFlowStore
{
    private const string SESSION_KEY = 'sso.flow';

    private const string RELAY_PREFIX = 'sso:saml:relay:';

    private const string TICKET_PREFIX = 'sso:saml:ticket:';

    /** A ticket only bridges the 303 from the ACS to the completion route. */
    private const int TICKET_TTL = 120;

    public function start(Request $request, IdentityProvider $provider, SsoClient $client, bool $test, ?string $returnUrl): SsoFlow
    {
        $flow = new SsoFlow(Str::random(40), $provider->uuid, $client, $test, $returnUrl, time());
        $request->session()->put(self::SESSION_KEY, $flow->toArray());

        return $flow;
    }

    /** The session's flow, removed (one use); null when absent or expired. */
    public function pull(Request $request): ?SsoFlow
    {
        $flow = SsoFlow::fromArray($request->session()->pull(self::SESSION_KEY));

        return ! $flow instanceof SsoFlow || $flow->isExpired() ? null : $flow;
    }

    public function rememberRelay(SsoFlow $flow, string $requestId): void
    {
        Cache::put(self::RELAY_PREFIX.hash('sha256', $flow->id), [
            'flow' => $flow->id, 'provider' => $flow->providerUuid, 'request_id' => $requestId,
        ], config()->integer('sso.flow_ttl', 600));
    }

    /** @return array{flow: string, provider: string, request_id: string}|null the relay entry, removed */
    public function pullRelay(string $relayState): ?array
    {
        $entry = $relayState === '' ? null : Cache::pull(self::RELAY_PREFIX.hash('sha256', $relayState));

        return is_array($entry) && is_string($entry['flow'] ?? null) && is_string($entry['provider'] ?? null) && is_string($entry['request_id'] ?? null)
            ? ['flow' => $entry['flow'], 'provider' => $entry['provider'], 'request_id' => $entry['request_id']]
            : null;
    }

    public function issueTicket(string $flowId, string $providerUuid, ExternalProfile|SsoRefusal $result): string
    {
        $ticket = Str::random(48);
        $payload = ['flow' => $flowId, 'provider' => $providerUuid] + ($result instanceof SsoRefusal
            ? ['refusal' => $result->value]
            : ['profile' => ProfileArray::from($result)]);
        Cache::put(self::TICKET_PREFIX.hash('sha256', $ticket), Crypt::encryptString((string) json_encode($payload)), self::TICKET_TTL);

        return $ticket;
    }

    /** @return array{flow: string, provider: string, result: ExternalProfile|SsoRefusal}|null the ticket, removed */
    public function redeemTicket(string $ticket): ?array
    {
        $sealed = $ticket === '' ? null : Cache::pull(self::TICKET_PREFIX.hash('sha256', $ticket));
        try {
            $data = is_string($sealed) ? json_decode(Crypt::decryptString($sealed), true) : null;
        } catch (DecryptException) {
            return null;
        }
        if (! is_array($data) || ! is_string($data['flow'] ?? null) || ! is_string($data['provider'] ?? null)) {
            return null;
        }
        $result = is_string($data['refusal'] ?? null) ? SsoRefusal::tryFrom($data['refusal']) : ProfileArray::to($data['profile'] ?? null);

        return $result === null ? null : ['flow' => $data['flow'], 'provider' => $data['provider'], 'result' => $result];
    }
}
