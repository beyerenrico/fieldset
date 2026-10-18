<?php

declare(strict_types=1);

namespace App\Domain\Instance\Compose;

/**
 * The quick tunnel's own network (plan KTD12, ADR 0082): cloudflared and the app, nothing else. Like the edge network
 * (EdgeNetwork, ADR 0075) it has a fixed private /28, chosen once per instance from its own pool (10.215.0.0/16, next
 * to the edge's 10.213/16 and the ACME harness's 10.214/16) and kept as `tunnel_subnet`, so cloudflared always sits at
 * the same address: the TCP peer the app recognises tunnel requests by (`tunnel.peer` in runtime.json). Docker hands out dynamic addresses only from the upper half (`ip_range`), so the app,
 * started first, never takes cloudflared's address.
 */
final class TunnelNetwork
{
    public const string NAME = 'tunnel';

    public const string POOL = '10.215.0.0';

    /** cloudflared's fixed address: the subnet's second host (the first is the gateway). */
    public static function peerAddress(string $subnet): string
    {
        return EdgeNetwork::edgeAddress($subnet);
    }

    /** @return array{subnet: string, ip_range: string, gateway: string} */
    public static function ipam(string $subnet): array
    {
        return EdgeNetwork::ipam($subnet);
    }

    /** @param  list<string>  $taken  subnets of the existing Docker networks */
    public static function choose(string $uuid, array $taken): string
    {
        return EdgeNetwork::choose($uuid, $taken, self::POOL);
    }
}
