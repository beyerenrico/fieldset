<?php

declare(strict_types=1);

namespace Tests\Support\Instance;

use App\Domain\Instance\Adopt\DataSnapshot;
use App\Domain\Instance\Adopt\LegacyInstall;
use App\Domain\Instance\Adopt\LegacyRuntime;
use App\Domain\Instance\Adopt\VolumeCopy;
use App\Domain\Instance\Compose\Services\AppRoles;
use App\Domain\Instance\Docker\ContainerInfo;
use App\Domain\Instance\Docker\ContainerSpec;
use App\Domain\Instance\Docker\DockerResource;
use App\Domain\Instance\Docker\Labels;
use App\Domain\Instance\Docker\NetworkInfo;
use App\Domain\Instance\Docker\ProcessOutcome;
use App\Domain\Instance\Operations\JournalRecord;
use App\Domain\Instance\Operations\OperationJournal;
use App\Domain\Instance\Restore\LegacyEnv;
use App\Domain\Instance\Restore\RestoreSpace;
use Closure;
use RuntimeException;

/**
 * A fresh instance directory (InstanceCommandFixture, nothing installed) next to a 1.0 checkout and its running Compose
 * project, for `fsa adopt` (plan U19) against the recording gateway: the checkout's compose.yaml, .env and package.json
 * as the 1.0.0 tag ships them; its six containers and two volumes labelled the way Compose labels them; and one-offs
 * that answer like the real images (du, pg_dump, pg_restore --list, tar, psql snapshots, amcheck, valkey-cli, cp,
 * pg_controldata). The copy's snapshot equals the 1.0 one unless `$copySnapshot` says otherwise.
 */
final class AdoptFixture
{
    public const string APP_KEY = 'base64:'.'MDEyMzQ1Njc4OWFiY2RlZjAxMjM0NTY3ODlhYmNkZWY=';

    public const string PROJECT = 'form-schema-app';

    public const array SERVICES = ['app', 'queue', 'scheduler', 'postgres', 'redis', 'gotenberg'];

    public InstanceCommandFixture $instance;

    public string $checkout;

    /** @var array<string, mixed> the 1.0 database as the probe reads it */
    public array $snapshot = [
        'postgres' => '16.4', 'migrations' => 120, 'last_migration' => '2026_10_25_100000_v1_last',
        'counts' => ['organizations' => 2, 'users' => 7, 'forms' => 3, 'form_versions' => 4, 'submissions' => 41, 'attachments' => 5, 'renditions' => 9, 'mail_messages' => 12, 'activity_log' => 210],
        'heads' => ['0' => ['seq' => 17, 'hash' => 'aaaa'], '1' => ['seq' => 180, 'hash' => 'bbbb']],
    ];

    /** @var array<string, mixed>|null what the copy holds (null: the same as 1.0) */
    public ?array $copySnapshot = null;

    /** @var array<string, mixed>|null what the running instance holds at uninstall (null: the baseline) */
    public ?array $laterSnapshot = null;

    /** Set by tests before `fsa uninstall`: the instance's probe then answers `$laterSnapshot`. */
    public bool $uninstalling = false;

    /** @var list<string> valkey-cli answers in turn (waiting, reserved, delayed); the last one repeats */
    public array $queue = ["0\n0\n0\n"];

    /** @var array<string, ProcessOutcome> entrypoint => a failing outcome */
    public array $failing = [];

    /** @var list<string> amcheck's notices */
    public array $amcheck = ['fsa-amcheck ok users_email_unique', 'fsa-amcheck ok organizations_slug_unique'];

    public string $clusterState = 'shut down';

    /** pg_controldata's latest checkpoint of 1.0's volume (LegacyFence); change it to pretend 1.0's Postgres ran again. */
    public string $checkpoint = '0/1A2B3C4';

    /** @var array<string, string>|null what 1.0's app roles run with (null: the checkout's .env) */
    public ?array $runningEnv = null;

    public string $pgMajor = '16';

    public string $osRelease = "NAME=\"Alpine Linux\"\nID=alpine\nVERSION_ID=3.22.2\n";

    /** @var array{dir: string, containers: array<string, ContainerInfo>, resources: list<DockerResource>}|null */
    private ?array $killed = null;

    /** @var list<string> the SQL statements psql got, in order */
    public array $sql = [];

    public function __construct(string $env = '', ?string $compose = null, string $version = '1.0.0')
    {
        $this->instance = new InstanceCommandFixture;
        $this->checkout = sys_get_temp_dir().'/fsa-test-checkout-'.bin2hex(random_bytes(4));
        mkdir($this->checkout, 0700, true);
        file_put_contents($this->checkout.'/compose.yaml', $compose ?? self::compose());
        file_put_contents($this->checkout.'/.env', $env === '' ? self::env() : $env);
        file_put_contents($this->checkout.'/package.json', json_encode(['name' => 'form-schema-app', 'version' => $version]));
        $this->withLegacyStack();
        app()->instance(RestoreSpace::FREE_BYTES, static fn (): int => 50 * 1024 ** 3);
        $run = $this->instance->docker->runResponder;
        $this->instance->docker->respondToRun(fn (ContainerSpec $spec): ?ProcessOutcome => $this->answerRun($spec) ?? ($run instanceof Closure ? $run($spec) : null));
        $this->instance->docker->fileRunResponder = $this->answerFile(...);
        $this->instance->docker->onCall['composeUp'] = $this->startInstance(...);
        // Proxy mode's `port` setup trusts the default network's gateway (ADR 0076).
        $this->instance->docker->networkResponder = static fn (string $name): ?NetworkInfo => str_ends_with($name, '_default') ? new NetworkInfo('net1', $name, false, ['172.31.0.0/16'], ['172.31.0.1']) : null;
    }

    public function cleanup(): void
    {
        exec('rm -rf '.escapeshellarg($this->checkout).($this->killed === null ? '' : ' '.escapeshellarg($this->killed['dir'])));
        TemporaryInstance::cleanup();
    }

    /** The production template's values that matter, plus unsafe ones adopt must not take over. */
    public static function env(array $overrides = []): string
    {
        $values = ['APP_ENV' => 'production', 'APP_KEY' => self::APP_KEY, 'APP_DEBUG' => 'true', 'APP_URL' => 'http://localhost:8080', 'APP_PORT' => '8080',
            'COMPOSE_FILE' => 'compose.yaml', 'DB_HOST' => 'postgres', 'DB_DATABASE' => 'form_schema', 'DB_USERNAME' => 'form_schema', 'DB_PASSWORD' => 'legacy-db-secret-1',
            'MAIL_HOST' => 'smtp.legacy.test', 'MAIL_PORT' => '587', 'MAIL_USERNAME' => 'mailer', 'MAIL_PASSWORD' => 'legacy-mail-secret-1',
            'MAIL_FROM_ADDRESS' => '"forms@legacy.test"', 'MAIL_FROM_NAME' => '"Legacy Forms"', 'SSO_ALLOW_INSECURE_IDP' => 'true', 'SSO_DNS_DRIVER' => 'config', 'TRUSTED_PROXIES' => '*', ...$overrides];

        return implode("\n", array_map(static fn (string $key, ?string $value): string => $value === null ? '' : $key.'='.$value, array_keys($values), $values))."\n";
    }

    /** compose.yaml as the v1.0.0 tag ships it, cut to what adopt reads. */
    public static function compose(string $dbHost = 'postgres'): string
    {
        return "name: form-schema-app\n\nx-app: &app\n  image: form-schema-app:local\n  env_file: .env\n  environment:\n    DB_CONNECTION: pgsql\n    DB_HOST: {$dbHost}\n"
            ."  volumes:\n    - app-storage:/var/www/html/storage/app\n\nservices:\n  app:\n    <<: *app\n    environment:\n      DB_HOST: {$dbHost}\n      RUN_MIGRATIONS: \"true\"\n"
            ."    ports:\n      - \"127.0.0.1:\${APP_PORT:-8080}:8080\"\n  queue:\n    <<: *app\n  scheduler:\n    <<: *app\n  postgres:\n    image: postgres:16-alpine\n"
            ."    volumes:\n      - pg-data:/var/lib/postgresql/data\n  redis:\n    image: valkey/valkey:8-alpine\n  gotenberg:\n    image: gotenberg/gotenberg:8.38.1-chromium\n\nvolumes:\n  pg-data:\n  app-storage:\n";
    }

    /** The 1.0 project's containers and volumes as Compose labels them (running unless stopped), with their mounts. */
    public function withLegacyStack(string $project = self::PROJECT, array $stopped = [], array $extraLabels = []): self
    {
        foreach (self::SERVICES as $index => $service) {
            $id = 'legacy'.$service.str_repeat((string) $index, 4);
            $mounts = match ($service) {
                'postgres' => [VolumeCopy::DATA_PATH => $project.'_'.LegacyInstall::DATA_VOLUME],
                'app', 'queue', 'scheduler' => [AppRoles::STORAGE_PATH => $project.'_'.LegacyInstall::STORAGE_VOLUME],
                default => [],
            };
            $this->instance->docker->containers[$id] = new ContainerInfo($id, $project.'-'.$service.'-1', in_array($service, $stopped, true) ? 'exited' : 'running', '2026-10-01T10:00:00Z', null,
                $service === 'postgres' ? 'postgres:16-alpine' : 'image', array_filter([
                    Labels::COMPOSE_PROJECT => $project, Labels::COMPOSE_SERVICE => $service, LegacyInstall::ONE_OFF => 'False',
                    LegacyInstall::WORKING_DIR => $this->checkout, LegacyInstall::CONFIG_FILES => $this->checkout.'/compose.yaml', ...$extraLabels,
                ], static fn (?string $value): bool => $value !== null), $mounts);
        }
        foreach ([LegacyInstall::DATA_VOLUME, LegacyInstall::STORAGE_VOLUME] as $volume) {
            $this->instance->docker->withResource(new DockerResource(DockerResource::VOLUME, $project.'_'.$volume, [Labels::COMPOSE_PROJECT => $project, LegacyInstall::VOLUME_LABEL => $volume]));
        }

        return $this;
    }

    public function legacyId(string $service): string
    {
        return 'legacy'.$service.str_repeat((string) array_search($service, self::SERVICES, true), 4);
    }

    /**
     * @param  array<string, mixed>  $parameters
     * @return array{0: int, 1: array<string, mixed>}
     */
    public function adopt(array $parameters = []): array
    {
        $this->syncEnvironment();

        return $this->instance->run('fsa:adopt', ['checkout' => $this->checkout, '--yes' => true, ...$parameters]);
    }

    /** 1.0's containers run with the checkout's `.env` (unless `$runningEnv` says otherwise) and Postgres 16 on Alpine. */
    public function syncEnvironment(): void
    {
        $env = $this->runningEnv ?? LegacyEnv::read((string) file_get_contents($this->checkout.'/.env'), LegacyRuntime::COMPARED);
        foreach (['app', 'queue', 'scheduler'] as $service) {
            $this->instance->docker->environments[$this->legacyId($service)] = $env;
        }
        $this->instance->docker->environments[$this->legacyId('postgres')] = ['PG_MAJOR' => $this->pgMajor];
    }

    /** Pretends 1.0 was started again in its checkout: every container runs, with a new start time. */
    public function restartLegacy(): void
    {
        foreach (self::SERVICES as $service) {
            $this->instance->docker->restartContainer($this->legacyId($service), '2026-10-20T11:11:11.000000001Z');
        }
    }

    /**
     * Snapshots the instance directory and Docker's state at the first Docker call made while `$phase` runs (`$latest`: at
     * its last one), as a process killed there would leave them; restoreKill() puts them back after the run (the dead
     * run's lock goes, as a stale one would).
     */
    public function killAt(string $phase, bool $latest = false): void
    {
        foreach (['runContainer', 'runContainerToFile', 'composeUp', 'composeRun', 'stopContainers', 'startContainers', 'containers'] as $method) {
            $previous = $this->instance->docker->onCall[$method] ?? null;
            $this->instance->docker->onCall[$method] = function (array $args) use ($phase, $latest, $previous): void {
                $this->snapshotIfIn($phase, $latest);
                if ($previous instanceof Closure) {
                    $previous($args);
                }
            };
        }
    }

    public function restoreKill(): void
    {
        $root = $this->instance->directory->root;
        if ($this->killed === null) {
            throw new RuntimeException('The run never reached the phase to kill.');
        }
        exec('rm -rf '.escapeshellarg($root).' && cp -Rp '.escapeshellarg($this->killed['dir']).' '.escapeshellarg($root).' && rm -rf '.escapeshellarg($root.'/locks').' '.escapeshellarg($this->killed['dir']));
        $this->instance->docker->containers = $this->killed['containers'];
        $this->instance->docker->resources = $this->killed['resources'];
        $this->killed = null;
        $this->instance->docker->onCall = array_intersect_key($this->instance->docker->onCall, ['composeUp' => true]);
        $this->instance->docker->onCall['composeUp'] = $this->startInstance(...);
    }

    private function snapshotIfIn(string $phase, bool $latest): void
    {
        $record = $this->killed === null || $latest ? new OperationJournal($this->instance->directory)->current() : null;
        if (! $record instanceof JournalRecord || $record->lastStartedPhase() !== $phase || $record->hasCompleted($phase)) {
            return;
        }
        if ($this->killed !== null) {
            exec('rm -rf '.escapeshellarg($this->killed['dir']));
        }
        $dir = sys_get_temp_dir().'/fsa-test-killed-'.bin2hex(random_bytes(4));
        exec('cp -Rp '.escapeshellarg($this->instance->directory->root).' '.escapeshellarg($dir));
        $this->killed = ['dir' => $dir, 'containers' => $this->instance->docker->containers, 'resources' => $this->instance->docker->resources];
    }

    /** @return list<ContainerSpec> */
    public function specs(string $entrypoint): array
    {
        return $this->instance->runSpecs($entrypoint);
    }

    /** @return list<string> legacy container IDs a gateway method got, flattened in call order */
    public function legacyCalls(string $method): array
    {
        return array_values(array_merge(...array_map(static fn (array $call): array => $call['ids'], $this->instance->docker->called($method)) ?: [[]]));
    }

    /** Pretends Compose started the instance's services: a running container per service of its project. */
    private function startInstance(array $args): void
    {
        if (! $this->instance->directory->hasState()) {
            return;
        }
        $state = $this->instance->state();
        foreach ($args['services'] === [] ? ['app', 'queue', 'scheduler', 'postgres', 'redis', 'gotenberg', 'mailpit', 'backup'] : $args['services'] as $service) {
            $id = 'fsa'.$service.'00000';
            $this->instance->docker->containers[$id] = new ContainerInfo($id, $state->project().'-'.$service.'-1', 'running', '2026-10-19T10:00:00Z', null, 'image',
                [Labels::COMPOSE_PROJECT => $state->project(), Labels::COMPOSE_SERVICE => $service, Labels::INSTANCE => $state->uuid(), Labels::ROLE => $service]);
        }
    }

    private function answerRun(ContainerSpec $spec): ?ProcessOutcome
    {
        return $this->failing[(string) $spec->entrypoint] ?? match ($spec->entrypoint) {
            'du' => new ProcessOutcome(0, "1073741824\t/legacy/pg-data\n2048\t/legacy/app-storage\n"),
            'psql' => $this->psql($spec),
            'valkey-cli' => new ProcessOutcome(0, count($this->queue) > 1 ? array_shift($this->queue) : $this->queue[0]),
            'pg_restore', 'cp' => new ProcessOutcome(0),
            'pg_controldata' => new ProcessOutcome(0, "pg_control version number:            1300\nDatabase cluster state:               {$this->clusterState}\nLatest checkpoint location:           {$this->checkpoint}\n"),
            'cat' => new ProcessOutcome(0, $this->osRelease),
            default => null,
        };
    }

    private function psql(ContainerSpec $spec): ProcessOutcome
    {
        $calls = $this->instance->docker->called('runContainer');
        $sql = (string) ($calls[array_key_last($calls)]['input'] ?? '');
        $this->sql[] = $sql;
        if (str_contains($sql, 'fsa-amcheck')) {
            return new ProcessOutcome(0, '', implode("\n", array_map(static fn (string $line): string => 'psql:<stdin>:21: NOTICE:  '.$line, $this->amcheck))."\n");
        }
        if (! str_contains($sql, 'json_build_object')) {
            return new ProcessOutcome(0);
        }
        $snapshot = match (true) {
            str_starts_with((string) $spec->network, 'container:legacy') => $this->snapshot,
            $this->uninstalling => $this->laterSnapshot ?? $this->copySnapshot ?? $this->snapshot,
            default => $this->copySnapshot ?? $this->snapshot,
        };

        return new ProcessOutcome(0, json_encode(DataSnapshot::fromArray($snapshot)->toArray(), JSON_THROW_ON_ERROR)."\n");
    }

    /** @param  list<string>  $command */
    private function answerFile(?string $entrypoint, array $command, string $file): ProcessOutcome
    {
        if (isset($this->failing['file:'.$entrypoint])) {
            return $this->failing['file:'.$entrypoint];
        }
        if ($entrypoint === 'tar') {
            new TarBuilder()->directory('./')->directory('./private/')->file('./private/attachments/a.jpg', 'jpeg')->writeTo($file, gzip: true);
        } else {
            file_put_contents($file, RecordingDockerGateway::fakeStream($entrypoint));
        }
        chmod($file, 0600);

        return new ProcessOutcome(0);
    }
}
