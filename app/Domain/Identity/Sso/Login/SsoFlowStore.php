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
use SocialiteProviders\OpenIDConnect\Provider as OidcProvider;

/**
 * Login flow state (ADR 0036). Flows live in the initiating session keyed by their id, so an admin login and
 * a Field App login started in the same browser do not replace each other (at most MAX_FLOWS, expired ones
 * are dropped).
 * - OIDC: the package keeps state, nonce and PKCE verifier under shared session keys; bindOidc() moves them
 *   into the flow, and the callback finds its flow by the returned state and puts them back (one use).
 * - SAML: the RelayState is a one-time flow id whose cache entry binds the IdP's POST to the AuthnRequest id;
 *   the session-less ACS hands the validated result over in an encrypted one-time ticket, redeemed by
 *   sso.saml.complete only for the session holding that flow. Relay and ticket are taken atomically (a cache
 *   lock around get-and-forget), so two concurrent posts cannot both redeem them.
 * Cache values are plain arrays/strings (LEARNINGS #39).
 */
final class SsoFlowStore
{
    private const string SESSION_KEY = 'sso.flows';

    private const int MAX_FLOWS = 5;

    /** The OIDC package's session keys (Socialite state and PKCE verifier, the package's nonce). */
    private const array OIDC_KEYS = ['state', OidcProvider::NONCE_SESSION_KEY, 'code_verifier'];

    private const string RELAY_PREFIX = 'sso:saml:relay:';

    private const string TICKET_PREFIX = 'sso:saml:ticket:';

    /** A ticket only bridges the 303 from the ACS to the completion route. */
    private const int TICKET_TTL = 120;

    private const int LOCK_SECONDS = 10;

    public function start(Request $request, IdentityProvider $provider, SsoClient $client, bool $test, ?string $returnUrl, ?int $invitation = null): SsoFlow
    {
        $flow = new SsoFlow(Str::random(40), $provider->uuid, $client, $test, $returnUrl, time(), $invitation);
        $entries = $this->entries($request);
        $entries[$flow->id] = $flow->toArray();
        $this->save($request, array_slice($entries, -self::MAX_FLOWS, null, true));

        return $flow;
    }

    /** After the OIDC redirect: the package's state, nonce and verifier move from the shared keys into the flow. */
    public function bindOidc(Request $request, SsoFlow $flow): void
    {
        $secrets = [];
        foreach (self::OIDC_KEYS as $key) {
            $secrets[$key] = $request->session()->pull($key);
        }
        $entries = $this->entries($request);
        if (isset($entries[$flow->id]) && is_string($secrets['state']) && $secrets['state'] !== '') {
            $entries[$flow->id]['oidc'] = $secrets;
            $entries[$flow->id]['state_hash'] = hash('sha256', $secrets['state']);
            $this->save($request, $entries);
        }
    }

    /** The OIDC flow the callback's state belongs to, removed (one use); its secrets go back for the package. */
    public function pullOidc(Request $request): ?SsoFlow
    {
        $state = $request->query('state');
        $hash = is_string($state) && $state !== '' ? hash('sha256', $state) : null;
        $entries = $this->entries($request);
        foreach ($entries as $id => $entry) {
            if ($hash !== null && is_string($entry['state_hash'] ?? null) && hash_equals($entry['state_hash'], $hash)) {
                unset($entries[$id]);
                $this->save($request, $entries);
                foreach (is_array($entry['oidc'] ?? null) ? $entry['oidc'] : [] as $key => $value) {
                    $request->session()->put((string) $key, $value);
                }

                return SsoFlow::fromArray($entry);
            }
        }

        return null;
    }

    /** Drops the OIDC secrets put back by pullOidc() when the callback ends before the package uses them. */
    public function clearOidc(Request $request): void
    {
        $request->session()->forget(self::OIDC_KEYS);
    }

    /** The flow with this id, removed (one use); null when absent or expired. */
    public function pull(Request $request, string $flowId): ?SsoFlow
    {
        $entries = $this->entries($request);
        $entry = $entries[$flowId] ?? null;
        unset($entries[$flowId]);
        $this->save($request, $entries);

        return SsoFlow::fromArray($entry);
    }

    /** The newest live flow of the session, kept (only to pick the client of an error page). */
    public function latest(Request $request): ?SsoFlow
    {
        $entries = $this->entries($request);

        return SsoFlow::fromArray(end($entries) ?: null);
    }

    public function forget(Request $request, SsoFlow $flow): void
    {
        $entries = $this->entries($request);
        unset($entries[$flow->id]);
        $this->save($request, $entries);
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
        $entry = $relayState === '' ? null : $this->pullOnce(self::RELAY_PREFIX.hash('sha256', $relayState));

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
        $sealed = $ticket === '' ? null : $this->pullOnce(self::TICKET_PREFIX.hash('sha256', $ticket));
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

    /** Atomic get-and-delete: a concurrent caller either waits for nothing (lock taken) or finds it gone. */
    private function pullOnce(string $key): mixed
    {
        $lock = Cache::lock($key.':pull', self::LOCK_SECONDS);
        if (! $lock->get()) {
            return null;
        }

        try {
            return Cache::pull($key);
        } finally {
            $lock->release();
        }
    }

    /** @return array<string, array<string, mixed>> live flows by id, oldest first */
    private function entries(Request $request): array
    {
        $stored = $request->session()->get(self::SESSION_KEY);
        $entries = [];
        foreach (is_array($stored) ? $stored : [] as $id => $entry) {
            $flow = SsoFlow::fromArray($entry);
            if (is_array($entry) && $flow instanceof SsoFlow && $flow->id === $id && ! $flow->isExpired()) {
                /** @var array<string, mixed> $entry */
                $entries[$flow->id] = $entry;
            }
        }

        return $entries;
    }

    /** @param  array<string, array<string, mixed>>  $entries */
    private function save(Request $request, array $entries): void
    {
        $entries === [] ? $request->session()->forget(self::SESSION_KEY) : $request->session()->put(self::SESSION_KEY, $entries);
    }
}
