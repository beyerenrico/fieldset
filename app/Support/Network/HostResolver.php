<?php

declare(strict_types=1);

namespace App\Support\Network;

/** DNS lookup behind an interface, so SSRF tests can make a host name resolve to any address. */
interface HostResolver
{
    /** @return list<string> IPv4 and IPv6 addresses of the host (an IP literal resolves to itself) */
    public function resolve(string $host): array;
}
