<?php

declare(strict_types=1);

namespace App\Domain\Instance\Docker;

/** A container attached to a network, as `docker network inspect` lists it (addresses without their prefix length). */
final readonly class NetworkEndpoint
{
    public function __construct(
        public string $containerId,
        public string $name,
        public ?string $ipv4 = null,
        public ?string $ipv6 = null,
    ) {}

    /** The address peers on the network see: IPv4 when it has one. */
    public function address(): ?string
    {
        return $this->ipv4 ?? $this->ipv6;
    }

    /** @param  array<string, mixed>  $json  one entry of the inspect output's `Containers` map */
    public static function fromInspect(string $containerId, array $json): self
    {
        return new self($containerId, self::string($json['Name'] ?? null) ?? '', self::ip($json['IPv4Address'] ?? null), self::ip($json['IPv6Address'] ?? null));
    }

    private static function ip(mixed $value): ?string
    {
        $address = self::string($value) === null ? '' : explode('/', (string) $value, 2)[0];

        return filter_var($address, FILTER_VALIDATE_IP) === false ? null : $address;
    }

    private static function string(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
