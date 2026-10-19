<?php

declare(strict_types=1);

namespace App\Domain\Instance\Adopt;

use App\Domain\Instance\Docker\ContainerInfo;
use App\Domain\Instance\Docker\DockerGateway;
use App\Domain\Instance\Docker\DockerResource;
use App\Domain\Instance\Docker\Labels;
use App\Domain\Instance\InstanceException;
use App\Domain\Instance\Output\ExitCode;

/**
 * The running 1.0 install `fsa adopt` takes over (plan U19, ADR 0087), as Docker reports it: the containers of its Compose
 * project (found by Compose's labels, one-offs left out) and which of them run, and its two volumes. Docker is the truth
 * about how 1.0 was started: the labels must name the checkout as working directory and its compose.yaml as the only
 * Compose file (an override is exit 7), and the project must have the bundled Postgres with its `pg-data` volume (exit 7
 * `external_database` otherwise). A project that carries an instance label is an fsa instance already (exit 7). The
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
        $all = array_values(array_filter($docker->containers([Labels::COMPOSE_PROJECT => $checkout->project]), static fn (ContainerInfo $c): bool => ($c->labels[self::ONE_OFF] ?? 'False') !== 'True'));
        if ($all === []) {
            throw self::refused("No container of the Compose project {$checkout->project} exists: is 1.0 installed from {$checkout->path}?", 'legacy_not_found', 'Start 1.0 in its checkout (`docker compose up -d`) and run adopt again; it copies the data of an existing install.');
        }
        self::assertOwnLabels($docker, $all, $checkout);
        $containers = [];
        $running = [];
        foreach ($all as $container) {
            $service = $container->service();
            if ($service !== null) {
                $containers[$service] = $container->id;
                if ($container->running()) {
                    $running[] = $service;
                }
            }
        }
        $volumes = self::volumes($docker, $checkout->project);
        if (! isset($containers[self::POSTGRES], $volumes[self::DATA_VOLUME])) {
            throw self::refused("The Compose project {$checkout->project} has no bundled Postgres with its pg-data volume.", 'external_database', 'Adopt copies the bundled database volume only; move the data with a backup instead (`fsa install --from-backup`).');
        }

        return new self($checkout->path, $checkout->project, $checkout->version, $containers, array_values(array_unique($running)), $volumes,
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

    /** @param  list<ContainerInfo>  $containers */
    private static function assertOwnLabels(DockerGateway $docker, array $containers, LegacyCheckout $checkout): void
    {
        if (array_any($containers, static fn (ContainerInfo $c): bool => isset($c->labels[Labels::INSTANCE]))) {
            throw self::refused("The Compose project {$checkout->project} belongs to an fsa instance, not to a 1.0 checkout.", 'already_instance', 'Manage it with that instance\'s fsa.');
        }
        // `docker ps` joins labels with commas, so a list of Compose files is read from the exact inspect output.
        $labels = $docker->inspectContainer($containers[0]->id)->labels ?? $containers[0]->labels;
        $dir = $labels[self::WORKING_DIR] ?? null;
        if ($dir !== null && self::real($dir) !== self::real($checkout->path)) {
            throw self::refused("The Compose project {$checkout->project} was started from {$dir}, not from {$checkout->path}.", 'checkout_mismatch', "Pass the checkout the 1.0 install runs from: fsa adopt {$dir}");
        }
        $files = array_values(array_filter(explode(',', $labels[self::CONFIG_FILES] ?? ''), static fn (string $file): bool => $file !== ''));
        $own = array_map(self::real(...), $files) === [self::real($checkout->path.'/'.LegacyCheckout::COMPOSE_FILE)];
        if ($files !== [] && ! $own) {
            throw self::refused('The 1.0 install was started with these Compose files: '.implode(', ', $files).'. Adopt copies only what compose.yaml of 1.0 defines.', 'compose_file_override', 'Restart 1.0 with compose.yaml alone (COMPOSE_FILE=compose.yaml, `docker compose up -d`), then run adopt again.');
        }
    }

    /** @return array<string, string> */
    private static function volumes(DockerGateway $docker, string $project): array
    {
        $volumes = [];
        foreach ($docker->volumes([Labels::COMPOSE_PROJECT => $project]) as $volume) {
            $key = $volume->labels[self::VOLUME_LABEL] ?? null;
            if (isset($volume->labels[Labels::INSTANCE])) {
                throw self::refused("The volume {$volume->name} belongs to an fsa instance.", 'already_instance', 'Manage it with that instance\'s fsa.');
            }
            if ($volume->kind === DockerResource::VOLUME && in_array($key, [self::DATA_VOLUME, self::STORAGE_VOLUME], true)) {
                $volumes[$key] = $volume->name;
            }
        }

        return $volumes;
    }

    private static function real(string $path): string
    {
        $real = realpath($path);

        return $real === false ? rtrim($path, '/') : $real;
    }

    private static function refused(string $message, string $code, string $hint): InstanceException
    {
        return new InstanceException($message, ExitCode::Incompatible, $code, $hint);
    }
}
