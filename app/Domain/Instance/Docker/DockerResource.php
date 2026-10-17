<?php

declare(strict_types=1);

namespace App\Domain\Instance\Docker;

/** A volume or network found by label (R63: uninstall and orphan checks act only on labelled resources). */
final readonly class DockerResource
{
    public const string VOLUME = 'volume';

    public const string NETWORK = 'network';

    /** @param  array<string, string>  $labels */
    public function __construct(
        public string $kind,
        public string $name,
        public array $labels = [],
    ) {}

    /** @param  array<string, mixed>  $json  one line of `docker volume ls` / `docker network ls --format '{{json .}}'` */
    public static function fromListing(string $kind, array $json): self
    {
        return new self($kind, is_string($json['Name'] ?? null) ? $json['Name'] : '', Labels::parse($json['Labels'] ?? null));
    }
}
