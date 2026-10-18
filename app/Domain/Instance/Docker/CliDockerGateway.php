<?php

declare(strict_types=1);

namespace App\Domain\Instance\Docker;

use App\Domain\Instance\Output\InvalidInput;

/**
 * DockerGateway over the docker CLI and Compose plugin (KTD1, KTD8), run through Symfony Process without a shell.
 *
 * - Compose calls always pass `--project-name`, `--file` and `--project-directory`, clear the caller's COMPOSE_*
 *   variables and disable the project's `.env` (COMPOSE_DISABLE_ENV_FILE), so a stray COMPOSE_FILE (the dev .env
 *   sets one) or a hand-made .env never changes what runs. Per-service `env_file`s are unaffected.
 * - Environment values for one-offs travel by name (`--env NAME`) in the CLI's process environment, never in
 *   argv, where `ps` would show them.
 */
final readonly class CliDockerGateway implements DockerGateway
{
    private const array CLEARED_ENV = [
        'COMPOSE_FILE', 'COMPOSE_PROJECT_NAME', 'COMPOSE_PROFILES', 'COMPOSE_ENV_FILES', 'COMPOSE_PATH_SEPARATOR',
        'COMPOSE_REMOVE_ORPHANS', 'COMPOSE_IGNORE_ORPHANS', 'COMPOSE_PARALLEL_LIMIT',
    ];

    private const array UNREACHABLE = [
        'Cannot connect to the Docker daemon', 'error during connect', 'permission denied while trying to connect',
    ];

    private const string NAME = '/^[A-Za-z0-9][A-Za-z0-9_.-]*$/D';

    private const string ENV_NAME = '/^[A-Za-z_][A-Za-z0-9_]*$/D';

    private const float QUERY_TIMEOUT = 120.0;

    public function __construct(
        private CommandRunner $runner,
        private string $binary = 'docker',
    ) {}

    public function versions(): DockerVersions
    {
        $engine = DockerJson::object($this->call(['version', '--format', '{{json .}}'], 'version')->stdout, 'version');
        $compose = trim($this->call(['compose', 'version', '--short'], 'compose version')->stdout);
        $version = static fn (mixed $part): string => is_array($part) && is_string($part['Version'] ?? null) ? $part['Version'] : '';

        return new DockerVersions($version($engine['Client'] ?? null), $version($engine['Server'] ?? null), ltrim($compose, 'v'));
    }

    public function info(): DockerInfo
    {
        return DockerInfo::fromJson(DockerJson::object($this->call(['info', '--format', '{{json .}}'], 'info')->stdout, 'info'));
    }

    public function composeUp(ComposeProject $project, array $services = [], ?int $waitTimeout = null, bool $removeOrphans = false): void
    {
        $flags = ['--detach', '--wait', ...($waitTimeout === null ? [] : ['--wait-timeout', (string) $waitTimeout]), ...($removeOrphans ? ['--remove-orphans'] : [])];
        $this->compose($project, ['up', ...$flags, ...$this->names($services)], 'compose up', null);
    }

    public function composeStop(ComposeProject $project, array $services = [], ?int $timeout = null): void
    {
        $this->compose($project, ['stop', ...($timeout === null ? [] : ['--timeout', (string) $timeout]), ...$this->names($services)], 'compose stop', null);
    }

    public function composeRm(ComposeProject $project, array $services = [], bool $volumes = false): void
    {
        $this->compose($project, ['rm', '--force', '--stop', ...($volumes ? ['--volumes'] : []), ...$this->names($services)], 'compose rm');
    }

    public function composeDown(ComposeProject $project, bool $volumes = false): void
    {
        $this->compose($project, ['down', '--remove-orphans', ...($volumes ? ['--volumes'] : [])], 'compose down', null);
    }

    public function composeRun(ComposeProject $project, string $service, array $command = [], array $env = [], ?string $user = null, ?string $input = null, ?float $timeout = null, bool $withDependencies = false): ProcessOutcome
    {
        $flags = ['--rm', '--no-TTY', ...($withDependencies ? [] : ['--no-deps']), ...($user === null ? [] : ['--user', $user]), ...$this->envFlags($env)];

        return $this->attempt([...$this->composeBase($project), 'run', ...$flags, ...$this->names([$service]), ...$command], $env, $input, $timeout);
    }

    public function composeRunToFile(ComposeProject $project, string $service, array $command, string $outputFile, array $env = [], ?string $entrypoint = null, ?float $timeout = null): ProcessOutcome
    {
        $flags = ['--rm', '--no-TTY', '--no-deps', ...($entrypoint === null ? [] : ['--entrypoint', ...$this->names([$entrypoint])]), ...$this->envFlags($env)];

        return $this->attempt([...$this->composeBase($project), 'run', ...$flags, ...$this->names([$service]), ...$command], $env, null, $timeout, $outputFile);
    }

    public function composeLogs(ComposeProject $project, array $services = [], ?string $since = null, ?int $tail = null): string
    {
        $flags = ['--no-color', '--timestamps', ...($since === null ? [] : ['--since', $since]), ...($tail === null ? [] : ['--tail', (string) $tail])];

        return $this->compose($project, ['logs', ...$flags, ...$this->names($services)], 'compose logs')->stdout;
    }

    public function composePs(ComposeProject $project): array
    {
        $lines = DockerJson::lines($this->compose($project, ['ps', '--all', '--format', 'json'], 'compose ps')->stdout, 'compose ps');

        return array_map(ContainerInfo::fromListing(...), $lines);
    }

    public function composeConfig(ComposeProject $project): array
    {
        return DockerJson::object($this->compose($project, ['config', '--format', 'json'], 'compose config')->stdout, 'compose config');
    }

    public function pullImage(string $reference, bool $enginePlatform = false): void
    {
        $this->succeed($this->attempt([$this->binary, 'pull', '--quiet', $reference], $this->platformEnv($enginePlatform), null, null), 'pull');
    }

    public function inspectImage(string $reference): ?ImageInfo
    {
        $outcome = $this->inspect(['image', 'inspect', '--format', '{{json .}}', $reference], 'image inspect');

        return $outcome instanceof ProcessOutcome ? ImageInfo::fromInspect(DockerJson::object($outcome->stdout, 'image inspect')) : null;
    }

    public function inspectContainer(string $id): ?ContainerInfo
    {
        $outcome = $this->inspect(['container', 'inspect', '--format', '{{json .}}', $id], 'container inspect');

        return $outcome instanceof ProcessOutcome ? ContainerInfo::fromInspect(DockerJson::object($outcome->stdout, 'container inspect')) : null;
    }

    public function inspectNetwork(string $name): ?NetworkInfo
    {
        $outcome = $this->inspect(['network', 'inspect', '--format', '{{json .}}', ...$this->names([$name])], 'network inspect');

        return $outcome instanceof ProcessOutcome ? NetworkInfo::fromInspect(DockerJson::object($outcome->stdout, 'network inspect')) : null;
    }

    public function containers(array $labels, bool $all = true): array
    {
        $args = ['container', 'ls', '--no-trunc', '--format', '{{json .}}', ...($all ? ['--all'] : []), ...$this->labelFilters($labels)];

        return array_map(ContainerInfo::fromListing(...), DockerJson::lines($this->call($args, 'container ls')->stdout, 'container ls'));
    }

    public function volumes(array $labels): array
    {
        $lines = DockerJson::lines($this->call(['volume', 'ls', '--format', '{{json .}}', ...$this->labelFilters($labels)], 'volume ls')->stdout, 'volume ls');

        return array_map(static fn (array $line): DockerResource => DockerResource::fromListing(DockerResource::VOLUME, $line), $lines);
    }

    public function networks(array $labels): array
    {
        $lines = DockerJson::lines($this->call(['network', 'ls', '--no-trunc', '--format', '{{json .}}', ...$this->labelFilters($labels)], 'network ls')->stdout, 'network ls');

        return array_map(static fn (array $line): DockerResource => DockerResource::fromListing(DockerResource::NETWORK, $line), $lines);
    }

    public function runContainer(ContainerSpec $spec, ?string $input = null, ?float $timeout = null): ProcessOutcome
    {
        $env = [...$spec->env, ...$this->platformEnv($spec->enginePlatform)];

        return $this->attempt([$this->binary, 'run', ...RunArguments::for($spec, $input !== null), ...$this->envFlags($spec->env), $spec->image, ...$spec->command], $env, $input, $timeout);
    }

    public function removeContainer(string $id, bool $force = true): void
    {
        $this->call(['container', 'rm', ...($force ? ['--force'] : []), $id], 'container rm');
    }

    public function removeVolume(string $name): void
    {
        $this->call(['volume', 'rm', $name], 'volume rm');
    }

    public function removeNetwork(string $name): void
    {
        $this->call(['network', 'rm', $name], 'network rm');
    }

    /** @return list<string> */
    private function composeBase(ComposeProject $project): array
    {
        return [$this->binary, 'compose', '--ansi', 'never', '--project-name', $project->name, '--file', $project->file, '--project-directory', $project->directory];
    }

    /** @param  list<string>  $args */
    private function compose(ComposeProject $project, array $args, string $label, ?float $timeout = self::QUERY_TIMEOUT): ProcessOutcome
    {
        return $this->succeed($this->attempt([...$this->composeBase($project), ...$args], [], null, $timeout), $label);
    }

    /** @param  list<string>  $args  arguments after the binary */
    private function call(array $args, string $label, ?float $timeout = self::QUERY_TIMEOUT): ProcessOutcome
    {
        return $this->succeed($this->attempt([$this->binary, ...$args], [], null, $timeout), $label);
    }

    /**
     * An inspect that answers null for "no such object".
     *
     * @param  list<string>  $args
     */
    private function inspect(array $args, string $label): ?ProcessOutcome
    {
        $outcome = $this->attempt([$this->binary, ...$args], [], null, self::QUERY_TIMEOUT);
        if (! $outcome->successful() && preg_match('/no such (object|image|container|network)|network \S+ not found/i', $outcome->stderr) === 1) {
            return null;
        }

        return $this->succeed($outcome, $label);
    }

    /**
     * Unsets DOCKER_DEFAULT_PLATFORM, which the ops container passes on otherwise (ADR 0070): the engine's own platform.
     *
     * @return array<string, false>
     */
    private function platformEnv(bool $enginePlatform): array
    {
        return $enginePlatform ? ['DOCKER_DEFAULT_PLATFORM' => false] : [];
    }

    /**
     * @param  list<string>  $command
     * @param  array<string, string|false>  $env  added to the inherited environment; false unsets
     */
    private function attempt(array $command, array $env, ?string $input, ?float $timeout, ?string $stdoutFile = null): ProcessOutcome
    {
        $outcome = $this->runner->run($command, [...array_fill_keys(self::CLEARED_ENV, false), 'COMPOSE_DISABLE_ENV_FILE' => '1', ...$env], $input, $timeout, $stdoutFile);
        if (! $outcome->started) {
            throw new DockerUnavailable('The docker CLI could not be started.', 'The fsa image ships the Docker CLI; check that the ops container runs the published image.');
        }
        foreach (self::UNREACHABLE as $needle) {
            if (! $outcome->successful() && str_contains($outcome->stderr, $needle)) {
                throw new DockerUnavailable('The Docker daemon is not reachable: '.$outcome->stderrTail(2));
            }
        }

        return $outcome;
    }

    private function succeed(ProcessOutcome $outcome, string $label): ProcessOutcome
    {
        return $outcome->successful() ? $outcome : throw new DockerCommandFailed($label, $outcome);
    }

    /**
     * @param  list<string>  $names
     * @return list<string>
     */
    private function names(array $names): array
    {
        foreach ($names as $name) {
            if (preg_match(self::NAME, $name) !== 1) {
                throw new InvalidInput("Invalid service name [{$name}].", 'invalid_service');
            }
        }

        return $names;
    }

    /**
     * @param  array<string, string>  $env
     * @return list<string>
     */
    private function envFlags(array $env): array
    {
        $flags = [];
        foreach (array_keys($env) as $name) {
            if (preg_match(self::ENV_NAME, $name) !== 1) {
                throw new InvalidInput("Invalid environment variable name [{$name}].", 'invalid_env_key');
            }
            $flags[] = '--env';
            $flags[] = $name;
        }

        return $flags;
    }

    /**
     * @param  array<string, string|null>  $labels
     * @return list<string>
     */
    private function labelFilters(array $labels): array
    {
        $filters = [];
        foreach ($labels as $key => $value) {
            $filters[] = '--filter';
            $filters[] = 'label='.$key.($value === null ? '' : '='.$value);
        }

        return $filters;
    }
}
