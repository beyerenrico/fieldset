<?php

declare(strict_types=1);

namespace Tests\Support\Instance;

use App\Domain\Instance\Docker\ComposeProject;
use App\Domain\Instance\Docker\ContainerInfo;
use App\Domain\Instance\Docker\ContainerSpec;
use App\Domain\Instance\Docker\DockerGateway;
use App\Domain\Instance\Docker\DockerInfo;
use App\Domain\Instance\Docker\DockerResource;
use App\Domain\Instance\Docker\DockerVersions;
use App\Domain\Instance\Docker\ImageInfo;
use App\Domain\Instance\Docker\Labels;
use App\Domain\Instance\Docker\NetworkInfo;
use App\Domain\Instance\Docker\ProcessOutcome;
use Closure;
use Throwable;

/**
 * Scripted Docker for unit and feature tests (plan U2): containers, images, volumes and networks it reports,
 * scripted outcomes and failures per method, and a call log (`calls`, `called()`, `methods()`), so tests can
 * assert e.g. that an idempotent re-run issued no `composeUp`. Bind with `RecordingDockerGateway::bind()`.
 */
final class RecordingDockerGateway implements DockerGateway
{
    /** @var list<array{method: string, args: array<string, mixed>}> */
    public array $calls = [];

    /** @var array<string, ContainerInfo> by container ID */
    public array $containers = [];

    /** @var array<string, ImageInfo> by reference */
    public array $images = [];

    /** @var list<DockerResource> */
    public array $resources = [];

    /** @var array<string, NetworkInfo> networks `inspectNetwork` reports, by name */
    public array $networkDetails = [];

    /** Answers inspectNetwork for names not in `networkDetails` (e.g. a project network named after a random UUID). */
    public ?Closure $networkResponder = null;

    /** @var array<string, list<ProcessOutcome>> queued outcomes for composeRun / runContainer */
    public array $outcomes = [];

    /** @var array<string, Throwable> */
    public array $failures = [];

    /** @var array<string, mixed> */
    public array $config = ['services' => []];

    public string $logs = '';

    /** @var list<string> what networkSubnets() answers (subnets of the engine's networks) */
    public array $subnets = ['172.17.0.0/16'];

    /** Runs before each runContainer outcome, e.g. to change files the way a concurrent process would. */
    public ?Closure $beforeRun = null;

    public DockerVersions $versions;

    public DockerInfo $info;

    /** Answers runContainer calls by spec (null falls back to the queue); e.g. port probes, cosign, smoke checks. */
    public ?Closure $runResponder = null;

    /** Answers composeRun calls by command (null falls back to the queue); e.g. one-off artisan commands. */
    public ?Closure $composeRunResponder = null;

    /**
     * Answers composeRunToFile calls: (entrypoint, command, output file) => outcome, writing the file itself.
     * Without one, a successful run writes a minimal pg_dump or tar stream (fakeStream()).
     */
    public ?Closure $fileRunResponder = null;

    public function __construct()
    {
        $this->versions = new DockerVersions('29.9.0', '29.9.0', '5.6.0');
        $this->info = new DockerInfo('29.9.0', 'Ubuntu 24.04', 'x86_64', 4 * 1024 ** 3, 2, false, '2026-10-18T10:00:00Z');
    }

    public static function bind(): self
    {
        $fake = new self;
        app()->instance(DockerGateway::class, $fake);

        return $fake;
    }

    /** @param  array<string, string>  $labels */
    public function withRunningContainer(string $id, string $startedAt, array $labels = [], string $name = ''): self
    {
        $this->containers[$id] = new ContainerInfo($id, $name === '' ? substr($id, 0, 12) : $name, 'running', $startedAt, null, 'image', $labels);

        return $this;
    }

    public function forgetContainer(string $id): self
    {
        unset($this->containers[$id]);

        return $this;
    }

    public function restartContainer(string $id, string $startedAt): self
    {
        $c = $this->containers[$id];
        $this->containers[$id] = new ContainerInfo($c->id, $c->name, 'running', $startedAt, $c->health, $c->image, $c->labels);

        return $this;
    }

    public function stopContainer(string $id): self
    {
        $c = $this->containers[$id];
        $this->containers[$id] = new ContainerInfo($c->id, $c->name, 'exited', $c->startedAt, $c->health, $c->image, $c->labels);

        return $this;
    }

    public function withImage(string $reference, ImageInfo $info): self
    {
        $this->images[$reference] = $info;

        return $this;
    }

    public function withNetwork(NetworkInfo $network): self
    {
        $this->networkDetails[$network->name] = $network;

        return $this;
    }

    public function withResource(DockerResource $resource): self
    {
        $this->resources[] = $resource;

        return $this;
    }

    public function failOn(string $method, Throwable $failure): self
    {
        $this->failures[$method] = $failure;

        return $this;
    }

    public function queueOutcome(string $method, ProcessOutcome $outcome): self
    {
        $this->outcomes[$method][] = $outcome;

        return $this;
    }

    /** @return list<array<string, mixed>> arguments of every call to the method */
    public function called(string $method): array
    {
        return array_values(array_map(static fn (array $call): array => $call['args'], array_filter($this->calls, static fn (array $call): bool => $call['method'] === $method)));
    }

    /** @return list<string> */
    public function methods(): array
    {
        return array_map(static fn (array $call): string => $call['method'], $this->calls);
    }

    public function versions(): DockerVersions
    {
        $this->record(__FUNCTION__);

        return $this->versions;
    }

    public function info(): DockerInfo
    {
        $this->record(__FUNCTION__);

        return $this->info;
    }

    /** @param  Closure(ContainerSpec): ?ProcessOutcome  $responder */
    public function respondToRun(Closure $responder): self
    {
        $this->runResponder = $responder;

        return $this;
    }

    /** @param  Closure(list<string>, ?string): ?ProcessOutcome  $responder  command and stdin */
    public function respondToComposeRun(Closure $responder): self
    {
        $this->composeRunResponder = $responder;

        return $this;
    }

    /** @return list<string> methods called since the given index of the call log */
    public function methodsSince(int $index): array
    {
        return array_slice($this->methods(), $index);
    }

    public function composeUp(ComposeProject $project, array $services = [], ?int $waitTimeout = null, bool $removeOrphans = false): void
    {
        $this->record(__FUNCTION__, ['project' => $project->name, 'services' => $services, 'waitTimeout' => $waitTimeout, 'removeOrphans' => $removeOrphans]);
    }

    public function composeStop(ComposeProject $project, array $services = [], ?int $timeout = null): void
    {
        $this->record(__FUNCTION__, ['project' => $project->name, 'services' => $services, 'timeout' => $timeout]);
    }

    public function composeRm(ComposeProject $project, array $services = [], bool $volumes = false): void
    {
        $this->record(__FUNCTION__, ['project' => $project->name, 'services' => $services, 'volumes' => $volumes]);
    }

    public function composeDown(ComposeProject $project, bool $volumes = false): void
    {
        $this->record(__FUNCTION__, ['project' => $project->name, 'volumes' => $volumes]);
    }

    public function composeRun(ComposeProject $project, string $service, array $command = [], array $env = [], ?string $user = null, ?string $input = null, ?float $timeout = null, bool $withDependencies = false): ProcessOutcome
    {
        $this->record(__FUNCTION__, ['project' => $project->name, 'service' => $service, 'command' => $command, 'env' => array_keys($env), 'user' => $user, 'timeout' => $timeout]);
        $answer = $this->composeRunResponder instanceof Closure ? ($this->composeRunResponder)($command, $input) : null;

        return $answer instanceof ProcessOutcome ? $answer : $this->nextOutcome(__FUNCTION__);
    }

    public function composeRunToFile(ComposeProject $project, string $service, array $command, string $outputFile, array $env = [], ?string $entrypoint = null, ?float $timeout = null): ProcessOutcome
    {
        $this->record(__FUNCTION__, ['project' => $project->name, 'service' => $service, 'command' => $command, 'file' => $outputFile, 'env' => array_keys($env), 'entrypoint' => $entrypoint, 'timeout' => $timeout]);
        $answer = $this->fileRunResponder instanceof Closure ? ($this->fileRunResponder)($entrypoint, $command, $outputFile) : null;
        if ($answer instanceof ProcessOutcome) {
            return $answer;
        }
        file_put_contents($outputFile, self::fakeStream($entrypoint));
        chmod($outputFile, 0600);

        return new ProcessOutcome(0);
    }

    /** The start of a pg_dump custom-format file, or one empty ustar block plus the end-of-archive blocks. */
    public static function fakeStream(?string $entrypoint): string
    {
        return $entrypoint === 'pg_dump'
            ? "PGDMP\x01\x0e\x00fake custom-format dump"
            : str_pad(str_pad('./', 257, "\0")."ustar\x0000", 512, "\0").str_repeat("\0", 1024);
    }

    public function composeLogs(ComposeProject $project, array $services = [], ?string $since = null, ?int $tail = null): string
    {
        $this->record(__FUNCTION__, ['project' => $project->name, 'services' => $services, 'since' => $since, 'tail' => $tail]);

        return $this->logs;
    }

    public function composePs(ComposeProject $project): array
    {
        $this->record(__FUNCTION__, ['project' => $project->name]);

        return $this->filter([Labels::COMPOSE_PROJECT => $project->name], true);
    }

    public function composeConfig(ComposeProject $project): array
    {
        $this->record(__FUNCTION__, ['project' => $project->name, 'file' => $project->file]);

        return $this->config;
    }

    public function pullImage(string $reference, bool $enginePlatform = false): void
    {
        $this->record(__FUNCTION__, ['reference' => $reference, ...($enginePlatform ? ['enginePlatform' => true] : [])]);
    }

    public function inspectImage(string $reference): ?ImageInfo
    {
        $this->record(__FUNCTION__, ['reference' => $reference]);

        return $this->images[$reference] ?? null;
    }

    public function inspectContainer(string $id): ?ContainerInfo
    {
        $this->record(__FUNCTION__, ['id' => $id]);

        return $this->containers[$id] ?? null;
    }

    public function inspectNetwork(string $name): ?NetworkInfo
    {
        $this->record(__FUNCTION__, ['name' => $name]);

        return $this->networkDetails[$name] ?? ($this->networkResponder instanceof Closure ? ($this->networkResponder)($name) : null);
    }

    public function containers(array $labels, bool $all = true): array
    {
        $this->record(__FUNCTION__, ['labels' => $labels, 'all' => $all]);

        return $this->filter($labels, $all);
    }

    public function volumes(array $labels): array
    {
        $this->record(__FUNCTION__, ['labels' => $labels]);

        return $this->resourcesOf(DockerResource::VOLUME, $labels);
    }

    public function networks(array $labels): array
    {
        $this->record(__FUNCTION__, ['labels' => $labels]);

        return $this->resourcesOf(DockerResource::NETWORK, $labels);
    }

    public function networkSubnets(): array
    {
        $this->record(__FUNCTION__);

        return $this->subnets;
    }

    public function runContainer(ContainerSpec $spec, ?string $input = null, ?float $timeout = null): ProcessOutcome
    {
        $this->record(__FUNCTION__, ['spec' => $spec, 'input' => $input, 'timeout' => $timeout]);
        if ($this->beforeRun instanceof Closure) {
            ($this->beforeRun)($spec);
        }
        $answer = $this->runResponder instanceof Closure ? ($this->runResponder)($spec) : null;

        return $answer instanceof ProcessOutcome ? $answer : $this->nextOutcome(__FUNCTION__, str_repeat('c', 64)."\n");
    }

    public function removeContainer(string $id, bool $force = true): void
    {
        $this->record(__FUNCTION__, ['id' => $id]);
        unset($this->containers[$id]);
    }

    public function removeVolume(string $name): void
    {
        $this->record(__FUNCTION__, ['name' => $name]);
        $this->forgetResource(DockerResource::VOLUME, $name);
    }

    public function removeNetwork(string $name): void
    {
        $this->record(__FUNCTION__, ['name' => $name]);
        $this->forgetResource(DockerResource::NETWORK, $name);
    }

    /** @param  array<string, mixed>  $args */
    private function record(string $method, array $args = []): void
    {
        $this->calls[] = ['method' => $method, 'args' => $args];
        if (isset($this->failures[$method])) {
            throw $this->failures[$method];
        }
    }

    private function nextOutcome(string $method, string $stdout = ''): ProcessOutcome
    {
        if (($this->outcomes[$method] ?? []) === []) {
            return new ProcessOutcome(0, $stdout);
        }

        return array_shift($this->outcomes[$method]);
    }

    /**
     * @param  array<string, string|null>  $labels
     * @return list<ContainerInfo>
     */
    private function filter(array $labels, bool $all): array
    {
        return array_values(array_filter(
            $this->containers,
            static fn (ContainerInfo $c): bool => Labels::matches($c->labels, $labels) && ($all || $c->running()),
        ));
    }

    /**
     * @param  array<string, string|null>  $labels
     * @return list<DockerResource>
     */
    private function resourcesOf(string $kind, array $labels): array
    {
        return array_values(array_filter($this->resources, static fn (DockerResource $r): bool => $r->kind === $kind && Labels::matches($r->labels, $labels)));
    }

    private function forgetResource(string $kind, string $name): void
    {
        $this->resources = array_values(array_filter($this->resources, static fn (DockerResource $r): bool => $r->kind !== $kind || $r->name !== $name));
    }
}
