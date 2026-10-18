<?php

declare(strict_types=1);

namespace App\Domain\Instance\Runtime;

use Carbon\CarbonImmutable;

/**
 * The quick tunnel as `fsa tunnel start` records it (KTD12, ADR 0057): the trycloudflare hostname, its expiry,
 * cloudflared's fixed address on its own network (`peer`, the TCP peer of every tunnel request) and whether
 * `--tunnel-admin` opened /admin through it.
 */
final readonly class TunnelState
{
    public function __construct(
        public ?string $hostname = null,
        public ?CarbonImmutable $expiresAt = null,
        public ?string $peer = null,
        public bool $admin = false,
    ) {}

    public static function none(): self
    {
        return new self;
    }

    public function isActive(CarbonImmutable $now): bool
    {
        return $this->hostname !== null && $this->expiresAt instanceof CarbonImmutable && $this->expiresAt->isAfter($now);
    }

    public function isExpired(CarbonImmutable $now): bool
    {
        return $this->hostname !== null && ! $this->isActive($now);
    }

    /** The active tunnel's origin, e.g. https://quiet-river-1234.trycloudflare.com. */
    public function url(CarbonImmutable $now): ?string
    {
        return $this->isActive($now) ? 'https://'.$this->hostname : null;
    }
}
