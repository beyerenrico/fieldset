<?php

declare(strict_types=1);

namespace App\Domain\Instance\Runtime;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * The app side of the upgrade gate (R62, KTD19, ADR 0074). While the gate is raised, or counts as raised because
 * the runtime file is unusable and nothing good was read before (fail closed), every request gets 503 with
 * Retry-After, except:
 *
 * - `/up` from loopback (TCP peer 127.0.0.0/8 or ::1): the container healthcheck `curl localhost:8080/up` keeps
 *   the app healthy behind the gate, so Compose does not restart or roll it back.
 * - a request carrying the operation's smoke token in `X-Fsa-Smoke-Token`, from a private address (client
 *   address after trusted-proxy resolution, so a request forwarded by the edge counts as its real client), never
 *   through the tunnel. The token is compared in constant time.
 */
final readonly class OperationGate
{
    public const string SMOKE_HEADER = 'X-Fsa-Smoke-Token';

    public const int RETRY_AFTER = 30;

    public const string HEALTH_PATH = 'up';

    /** Docker bridge and overlay networks, loopback and IPv6 unique-local addresses. */
    public const array INTERNAL_NETWORKS = ['10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16', '127.0.0.0/8', '::1/128', 'fc00::/7'];

    private const array LOOPBACK = ['127.0.0.0/8', '::1/128'];

    public function admits(RuntimeState $state, Request $request, bool $viaTunnel): bool
    {
        if (! $state->gate->raised) {
            return true;
        }
        if ($viaTunnel) {
            return false;
        }

        return $this->isLocalHealthCheck($request) || $this->carriesSmokeToken($state->gate, $request);
    }

    private function isLocalHealthCheck(Request $request): bool
    {
        return $request->is(self::HEALTH_PATH) && $this->inNetworks((string) $request->server->get('REMOTE_ADDR', ''), self::LOOPBACK);
    }

    private function carriesSmokeToken(GateState $gate, Request $request): bool
    {
        $given = $request->headers->get(self::SMOKE_HEADER);
        if ($gate->smokeToken === null || ! is_string($given) || $given === '') {
            return false;
        }

        return hash_equals($gate->smokeToken, $given) && $this->inNetworks((string) $request->ip(), self::INTERNAL_NETWORKS);
    }

    /** @param  list<string>  $networks */
    private function inNetworks(string $address, array $networks): bool
    {
        $address = HostName::canonicalIp($address);

        return $address !== null && IpUtils::checkIp($address, $networks);
    }
}
