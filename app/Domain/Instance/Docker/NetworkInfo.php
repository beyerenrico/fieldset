<?php

declare(strict_types=1);

namespace App\Domain\Instance\Docker;

/**
 * A network as `docker network inspect` describes it: its subnets and gateways and the containers attached to it.
 * Proxy mode reads the proxy's address from it (ADR 0076), the `port` setup the gateway its loopback port's
 * connections arrive from.
 */
final readonly class NetworkInfo
{
    /**
     * @param  list<string>  $subnets
     * @param  list<string>  $gateways
     * @param  list<NetworkEndpoint>  $endpoints
     * @param  array<string, string>  $labels
     */
    public function __construct(
        public string $id,
        public string $name,
        public bool $internal = false,
        public array $subnets = [],
        public array $gateways = [],
        public array $endpoints = [],
        public array $labels = [],
    ) {}

    /** @param  array<string, mixed>  $json  one document of `docker network inspect --format '{{json .}}'` */
    public static function fromInspect(array $json): self
    {
        $ipam = is_array($json['IPAM'] ?? null) && is_array($json['IPAM']['Config'] ?? null) ? $json['IPAM']['Config'] : [];
        $pick = static fn (string $key): array => array_values(array_filter(array_map(
            static fn (mixed $config): ?string => is_array($config) && is_string($config[$key] ?? null) && $config[$key] !== '' ? $config[$key] : null,
            $ipam,
        )));
        $containers = is_array($json['Containers'] ?? null) ? $json['Containers'] : [];
        $endpoints = [];
        foreach ($containers as $id => $endpoint) {
            $endpoints[] = NetworkEndpoint::fromInspect((string) $id, is_array($endpoint) ? $endpoint : []);
        }

        return new self(
            id: is_string($json['Id'] ?? null) ? $json['Id'] : '',
            name: is_string($json['Name'] ?? null) ? $json['Name'] : '',
            internal: ($json['Internal'] ?? false) === true,
            subnets: $pick('Subnet'),
            gateways: array_values(array_filter($pick('Gateway'), static fn (string $ip): bool => filter_var($ip, FILTER_VALIDATE_IP) !== false)),
            endpoints: $endpoints,
            labels: Labels::parse($json['Labels'] ?? null),
        );
    }

    /** The IPv4 gateway (the bridge's own address), else the first one. */
    public function gateway(): ?string
    {
        foreach ($this->gateways as $gateway) {
            if (filter_var($gateway, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
                return $gateway;
            }
        }

        return $this->gateways[0] ?? null;
    }

    /** The endpoint of a container by name or ID (a unique ID prefix of at least 12 characters counts). */
    public function endpoint(string $container): ?NetworkEndpoint
    {
        $name = ltrim($container, '/');
        $matches = array_values(array_filter($this->endpoints, static fn (NetworkEndpoint $endpoint): bool => $endpoint->name === $name
            || $endpoint->containerId === $name
            || (strlen($name) >= 12 && str_starts_with($endpoint->containerId, $name))));

        return count($matches) === 1 ? $matches[0] : null;
    }

    /** The endpoint holding an address on this network. */
    public function endpointAt(string $address): ?NetworkEndpoint
    {
        $packed = inet_pton($address);
        foreach ($this->endpoints as $endpoint) {
            foreach ([$endpoint->ipv4, $endpoint->ipv6] as $candidate) {
                if ($packed !== false && $candidate !== null && inet_pton($candidate) === $packed) {
                    return $endpoint;
                }
            }
        }

        return null;
    }
}
