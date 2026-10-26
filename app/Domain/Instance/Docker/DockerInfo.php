<?php

declare(strict_types=1);

namespace App\Domain\Instance\Docker;

/**
 * What the Docker engine reports about itself (`docker info`): the doctor's view of the host as the daemon sees
 * it (BUILD_PROMPT_V2 R10). On Docker Desktop that is the VM, which is what the containers get.
 */
final readonly class DockerInfo
{
    public function __construct(
        public string $serverVersion,
        public string $operatingSystem = '',
        public string $architecture = '',
        public int $memoryBytes = 0,
        public int $cpus = 0,
        public bool $rootless = false,
        public ?string $systemTime = null,
    ) {}

    /** @param  array<string, mixed>  $json  `docker info --format '{{json .}}'` */
    public static function fromJson(array $json): self
    {
        $options = is_array($json['SecurityOptions'] ?? null) ? $json['SecurityOptions'] : [];

        return new self(
            serverVersion: self::string($json['ServerVersion'] ?? null),
            operatingSystem: self::string($json['OperatingSystem'] ?? null),
            architecture: self::string($json['Architecture'] ?? null),
            memoryBytes: is_int($json['MemTotal'] ?? null) ? $json['MemTotal'] : (int) self::string($json['MemTotal'] ?? null),
            cpus: is_int($json['NCPU'] ?? null) ? $json['NCPU'] : 0,
            rootless: array_any($options, static fn (mixed $option): bool => is_string($option) && str_contains($option, 'name=rootless')),
            systemTime: self::string($json['SystemTime'] ?? null) ?: null,
        );
    }

    /** On a WSL 2 host: Docker Desktop or Docker Engine in the distro, by the prefix of OperatingSystem (as the shim reads it). */
    public function wslBackend(): WslBackend
    {
        return WslBackend::fromOperatingSystem($this->operatingSystem);
    }

    /** `amd64` or `arm64` (Docker reports `x86_64` and `aarch64`), else what the engine said. */
    public function platformArchitecture(): string
    {
        return match (strtolower($this->architecture)) {
            'x86_64', 'amd64' => 'amd64',
            'aarch64', 'arm64' => 'arm64',
            default => strtolower($this->architecture),
        };
    }

    private static function string(mixed $value): string
    {
        return is_string($value) || is_int($value) ? (string) $value : '';
    }
}
