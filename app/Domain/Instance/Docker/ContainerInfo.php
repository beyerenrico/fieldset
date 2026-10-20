<?php

declare(strict_types=1);

namespace App\Domain\Instance\Docker;

/**
 * A container as `docker container inspect`, `docker container ls` or `docker compose ps` describes it.
 * `startedAt` comes only from inspect; the instance lock compares it to detect a restarted holder (KTD4).
 */
final readonly class ContainerInfo
{
    /**
     * @param  array<string, string>  $labels
     * @param  array<string, string>  $mounts  destination => volume name, named volumes only (inspect only)
     */
    public function __construct(
        public string $id,
        public string $name,
        public string $state,
        public ?string $startedAt = null,
        public ?string $health = null,
        public ?string $image = null,
        public array $labels = [],
        public array $mounts = [],
    ) {}

    public function running(): bool
    {
        return $this->state === 'running';
    }

    public function service(): ?string
    {
        return $this->labels[Labels::COMPOSE_SERVICE] ?? null;
    }

    /** @param  array<string, mixed>  $json  one document of `docker container inspect --format '{{json .}}'` */
    public static function fromInspect(array $json): self
    {
        $state = is_array($json['State'] ?? null) ? $json['State'] : [];
        $config = is_array($json['Config'] ?? null) ? $json['Config'] : [];
        $health = is_array($state['Health'] ?? null) ? self::string($state['Health']['Status'] ?? null) : null;

        return new self(
            id: self::string($json['Id'] ?? null) ?? '',
            name: ltrim(self::string($json['Name'] ?? null) ?? '', '/'),
            state: self::string($state['Status'] ?? null) ?? 'unknown',
            startedAt: self::string($state['StartedAt'] ?? null),
            health: $health,
            image: self::string($config['Image'] ?? null),
            labels: Labels::parse($config['Labels'] ?? null),
            mounts: self::volumes($json['Mounts'] ?? null),
        );
    }

    /** @return array<string, string> the named volumes among inspect's `Mounts`, by destination */
    private static function volumes(mixed $mounts): array
    {
        $volumes = [];
        foreach (is_array($mounts) ? $mounts : [] as $mount) {
            if (is_array($mount) && ($mount['Type'] ?? null) === 'volume' && is_string($mount['Name'] ?? null) && is_string($mount['Destination'] ?? null)) {
                $volumes[$mount['Destination']] = $mount['Name'];
            }
        }

        return $volumes;
    }

    /** @param  array<string, mixed>  $json  one line of `docker container ls --format '{{json .}}'` or `docker compose ps --format json` */
    public static function fromListing(array $json): self
    {
        $health = self::string($json['Health'] ?? null);

        return new self(
            id: self::string($json['ID'] ?? null) ?? '',
            name: self::string($json['Names'] ?? $json['Name'] ?? null) ?? '',
            state: self::string($json['State'] ?? null) ?? 'unknown',
            health: $health === '' ? null : $health,
            image: self::string($json['Image'] ?? null),
            labels: Labels::parse($json['Labels'] ?? null),
        );
    }

    private static function string(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }
}
