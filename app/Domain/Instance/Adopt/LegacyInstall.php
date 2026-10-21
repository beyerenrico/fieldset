<?php

declare(strict_types=1);

namespace App\Domain\Instance\Adopt;

use App\Domain\Instance\Compose\Services\AppRoles;
use App\Domain\Instance\Docker\ContainerInfo;
use App\Domain\Instance\Docker\DockerGateway;
use App\Domain\Instance\Docker\Labels;
use App\Domain\Instance\InstanceException;
use App\Domain\Instance\Output\ExitCode;

/**
 * The running 1.0 install `fieldset adopt` takes over (plan U19, ADR 0087), as Docker reports it: the containers of its Compose
 * project (found by Compose's labels, one-offs left out) and which of them run, and its two volumes. Docker is the truth
 * about how 1.0 was started: the labels must name the checkout as working directory and its compose.yaml as the only
 * Compose file (an override is exit 7), and the project must have the bundled Postgres with its `pg-data` volume (exit 7
 * `external_database` otherwise). A project that carries an instance label is a fieldset instance already (exit 7). The
 * summary goes into the adopt journal, so a resumed adopt and a rollback know exactly which containers to start again.
 */
final readonly class LegacyInstall
{
    public const string WORKING_DIR = 'com.docker.compose.project.working_dir';

    public const string CONFIG_FILES = 'com.docker.compose.project.config_files';

    public const string ONE_OFF = 'com.docker.compose.oneoff';

    public const string VOLUME_LABEL = 'com.docker.compose.volume';

    public const string POSTGRES = 'postgres';

    /** 1.0's services that write: stopped first. */
    public const array WRITERS = ['app', 'scheduler'];

    public const string QUEUE = 'queue';

    /** Stopped last, started first. */
    public const array INFRASTRUCTURE = [self::POSTGRES, 'redis', 'gotenberg'];

    public const string DATA_VOLUME = 'pg-data';

    public const string STORAGE_VOLUME = 'app-storage';

    /**
     * @param  array<string, string>  $containers  service => container ID
     * @param  list<string>  $running  services whose container ran when adopt began
     * @param  array<string, string>  $volumes  `pg-data` / `app-storage` => volume name
     */
    public function __construct(
        public string $checkout,
        public string $project,
        public string $version,
        public array $containers,
        public array $running,
        public array $volumes,
        public string $database,
        public string $username,
        public int $port,
        public ?string $url,
    ) {}

    /** @throws InstanceException exit 7 */
    public static function discover(DockerGateway $docker, LegacyCheckout $checkout): self
    {
        $found = LegacyContainers::find($docker, $checkout->project, $checkout->path);
        if ($found === []) {
            throw self::refused("No container of the Compose project {$checkout->project} exists: is 1.0 installed from {$checkout->path}?", 'legacy_not_found', 'Start 1.0 in its checkout (`docker compose up -d`) and run adopt again; it copies the data of an existing install.');
        }
        $volumes = self::volumes($docker, $found);
        if (! isset($found[self::POSTGRES], $volumes[self::DATA_VOLUME])) {
            throw self::refused("The Compose project {$checkout->project} has no bundled Postgres with a pg-data volume.", 'external_database', 'Adopt copies the bundled database volume only; move the data with a 1.0 backup instead (`install.sh --dir <new directory> --from-backup <backup> --accept-unauthenticated`).');
        }
        $running = array_map(strval(...), array_keys(array_filter($found, static fn (ContainerInfo $c): bool => $c->running())));

        return new self($checkout->path, $checkout->project, $checkout->version, array_map(static fn (ContainerInfo $c): string => $c->id, $found), $running, $volumes,
            $checkout->database(), $checkout->username(), $checkout->port(), $checkout->url());
    }

    /** @param  array<string, mixed>  $data */
    public static function fromArray(array $data): self
    {
        $strings = static fn (mixed $value): array => is_array($value) ? array_filter($value, is_string(...)) : [];

        return new self(
            (string) ($data['checkout'] ?? ''), (string) ($data['project'] ?? ''), (string) ($data['version'] ?? ''),
            $strings($data['containers'] ?? []), array_values($strings($data['running'] ?? [])), $strings($data['volumes'] ?? []),
            (string) ($data['database'] ?? ''), (string) ($data['username'] ?? ''), (int) ($data['port'] ?? 0),
            is_string($data['url'] ?? null) ? $data['url'] : null,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['checkout' => $this->checkout, 'project' => $this->project, 'version' => $this->version, 'containers' => $this->containers,
            'running' => $this->running, 'volumes' => $this->volumes, 'database' => $this->database, 'username' => $this->username,
            'port' => $this->port, 'url' => $this->url];
    }

    /** @param  array<string, string>  $containers  service => container ID */
    public function withContainers(array $containers): self
    {
        return new self($this->checkout, $this->project, $this->version, $containers, $this->running, $this->volumes, $this->database, $this->username, $this->port, $this->url);
    }

    /**
     * Container IDs of these services that ran when adopt began, in the order given.
     *
     * @param  list<string>  $services
     * @return list<string>
     */
    public function runningIds(array $services): array
    {
        return array_values(array_map(fn (string $service): string => $this->containers[$service], array_filter($services, fn (string $service): bool => in_array($service, $this->running, true) && isset($this->containers[$service]))));
    }

    public function postgres(): string
    {
        return $this->containers[self::POSTGRES];
    }

    /** The project's default network, where its `redis` service answers (QueueDrain). */
    public function network(): string
    {
        return $this->project.'_default';
    }

    /**
     * The volumes the containers really mount (review S3), not the ones that merely carry a label: Postgres's data
     * directory and the storage directory of the app roles. A volume with an instance label is refused.
     *
     * @param  array<string, ContainerInfo>  $found
     * @return array<string, string>
     */
    private static function volumes(DockerGateway $docker, array $found): array
    {
        $storage = null;
        foreach (['app', 'queue', 'scheduler'] as $service) {
            $storage ??= isset($found[$service]) ? ($found[$service]->mounts[AppRoles::STORAGE_PATH] ?? null) : null;
        }
        $data = isset($found[self::POSTGRES]) ? ($found[self::POSTGRES]->mounts[VolumeCopy::DATA_PATH] ?? null) : null;
        $volumes = array_filter([self::DATA_VOLUME => $data, self::STORAGE_VOLUME => $storage], is_string(...));
        foreach ($docker->volumes([Labels::INSTANCE => null]) as $volume) {
            if (in_array($volume->name, $volumes, true)) {
                throw self::refused("The volume {$volume->name} belongs to a fieldset instance.", 'already_instance', 'Manage it with that instance\'s fieldset.');
            }
        }

        return $volumes;
    }

    private static function refused(string $message, string $code, string $hint): InstanceException
    {
        return new InstanceException($message, ExitCode::Incompatible, $code, $hint);
    }
}
