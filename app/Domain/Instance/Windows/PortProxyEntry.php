<?php

declare(strict_types=1);

namespace App\Domain\Instance\Windows;

/**
 * One `netsh interface portproxy show v4tov4` line. `listenAddress` null: every address (`*`); `connectAddress` null:
 * a host name instead of an IPv4 address (never a current WSL address, so stale).
 */
final readonly class PortProxyEntry
{
    public function __construct(
        public ?string $listenAddress,
        public int $listenPort,
        public ?string $connectAddress,
        public int $connectPort,
    ) {}
}
