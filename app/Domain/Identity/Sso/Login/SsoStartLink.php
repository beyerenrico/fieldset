<?php

declare(strict_types=1);

namespace App\Domain\Identity\Sso\Login;

use App\Domain\Identity\Models\IdentityProvider;
use App\Domain\Identity\Sso\Enums\SsoClient;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Binds the start of an SSO login to a step of this application (ADR 0036, login CSRF): `sso.redirect` is a
 * GET that any site can link to, so for the admin and app clients it only starts a flow with a `start` token
 * issued into the same session by a CSRF-protected step — home-realm discovery (POST /auth/sso/discover,
 * the admin login's Livewire "Continue"), the password gate's refusal, or a signed invitation link. The token
 * is random, kept hashed in the session with its provider, client and (for invitations) the invited
 * membership, and valid for `sso.flow_ttl` seconds; a foreign page can neither plant nor guess it. Test mode
 * needs no token: it requires a signed-in member with members.manage.
 */
final readonly class SsoStartLink
{
    public const string PARAMETER = 'start';

    private const string SESSION_KEY = 'sso.starts';

    private const int MAX_TOKENS = 10;

    /** A login start URL that only this session can use. */
    public function url(IdentityProvider $provider, SsoClient $client, ?string $loginHint = null, ?int $invitation = null): string
    {
        $token = Str::random(40);
        $starts = $this->live(session()->get(self::SESSION_KEY));
        $starts[hash('sha256', $token)] = [
            'provider' => $provider->uuid,
            'client' => $client->value,
            'invitation' => $invitation,
            'expires' => time() + config()->integer('sso.flow_ttl', 600),
        ];
        session()->put(self::SESSION_KEY, array_slice($starts, -self::MAX_TOKENS, null, true));

        $url = $provider->redirectUrl($client, $loginHint);

        return $url.(str_contains($url, '?') ? '&' : '?').http_build_query([self::PARAMETER => $token]);
    }

    /**
     * The start this request's token stands for, or null when it is missing, expired, of another session,
     * provider or client.
     *
     * @return array{invitation: int|null}|null
     */
    public function redeem(Request $request, IdentityProvider $provider, SsoClient $client): ?array
    {
        $token = $request->query(self::PARAMETER);
        if (! is_string($token) || $token === '') {
            return null;
        }
        $start = $this->live($request->session()->get(self::SESSION_KEY))[hash('sha256', $token)] ?? null;
        if ($start === null || ! hash_equals($start['provider'], $provider->uuid) || $start['client'] !== $client->value) {
            return null;
        }

        return ['invitation' => $start['invitation']];
    }

    /**
     * @return array<string, array{provider: string, client: string, invitation: int|null, expires: int}>
     */
    private function live(mixed $stored): array
    {
        $live = [];
        foreach (is_array($stored) ? $stored : [] as $hash => $start) {
            if (is_string($hash) && is_array($start) && is_string($start['provider'] ?? null) && is_string($start['client'] ?? null)
                && is_int($start['expires'] ?? null) && $start['expires'] >= time()) {
                $live[$hash] = [
                    'provider' => $start['provider'],
                    'client' => $start['client'],
                    'invitation' => is_int($start['invitation'] ?? null) ? $start['invitation'] : null,
                    'expires' => $start['expires'],
                ];
            }
        }

        return $live;
    }
}
