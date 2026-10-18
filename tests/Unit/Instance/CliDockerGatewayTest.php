<?php

declare(strict_types=1);

use App\Domain\Instance\Docker\CliDockerGateway;
use App\Domain\Instance\Docker\ComposeProject;
use App\Domain\Instance\Docker\ContainerSpec;
use App\Domain\Instance\Docker\DockerCommandFailed;
use App\Domain\Instance\Docker\DockerResource;
use App\Domain\Instance\Docker\DockerUnavailable;
use App\Domain\Instance\Docker\ProcessOutcome;
use App\Domain\Instance\Docker\SymfonyCommandRunner;
use App\Domain\Instance\InstanceException;
use App\Domain\Instance\Output\ExitCode;
use App\Domain\Instance\Output\InvalidInput;
use Tests\Support\Instance\ScriptedCommandRunner;
use Tests\Support\Instance\TemporaryInstance;

const COMPOSE_BASE = ['docker', 'compose', '--ansi', 'never', '--project-name', 'fsa-forms-ab12', '--file', '/srv/forms/compose.yaml', '--project-directory', '/srv/forms'];

beforeEach(function (): void {
    $this->runner = new ScriptedCommandRunner;
    $this->docker = new CliDockerGateway($this->runner);
    $this->project = new ComposeProject('fsa-forms-ab12', '/srv/forms/compose.yaml', '/srv/forms');
});

afterEach(fn () => TemporaryInstance::cleanup());

it('builds compose lifecycle commands with an explicit project, file and directory', function (Closure $call, array $args, ?float $timeout): void {
    $call($this->docker, $this->project);

    expect($this->runner->lastCommand())->toBe([...COMPOSE_BASE, ...$args])
        ->and($this->runner->runs[0]['timeout'])->toBe($timeout);
})->with([
    'up' => [fn ($d, $p) => $d->composeUp($p), ['up', '--detach', '--wait'], null],
    'up services' => [fn ($d, $p) => $d->composeUp($p, ['app', 'queue'], 300, true), ['up', '--detach', '--wait', '--wait-timeout', '300', '--remove-orphans', 'app', 'queue'], null],
    'stop' => [fn ($d, $p) => $d->composeStop($p, ['app'], 30), ['stop', '--timeout', '30', 'app'], null],
    'stop all' => [fn ($d, $p) => $d->composeStop($p), ['stop'], null],
    'rm' => [fn ($d, $p) => $d->composeRm($p, ['mailpit'], true), ['rm', '--force', '--stop', '--volumes', 'mailpit'], 120.0],
    'down' => [fn ($d, $p) => $d->composeDown($p, true), ['down', '--remove-orphans', '--volumes'], null],
    'logs' => [fn ($d, $p) => $d->composeLogs($p, ['app'], '10m', 200), ['logs', '--no-color', '--timestamps', '--since', '10m', '--tail', '200', 'app'], 120.0],
    'logs all' => [fn ($d, $p) => $d->composeLogs($p), ['logs', '--no-color', '--timestamps'], 120.0],
]);

it('clears the caller\'s COMPOSE_* variables and disables the project .env', function (): void {
    $this->docker->composeUp($this->project);

    $env = $this->runner->runs[0]['env'];
    expect($env['COMPOSE_FILE'])->toBeFalse()
        ->and($env['COMPOSE_PROJECT_NAME'])->toBeFalse()
        ->and($env['COMPOSE_PROFILES'])->toBeFalse()
        ->and($env['COMPOSE_DISABLE_ENV_FILE'])->toBe('1');
});

it('runs one-offs without the TTY and passes environment values by name only', function (): void {
    $this->runner->push(new ProcessOutcome(3, 'out', 'migration failed'));

    $outcome = $this->docker->composeRun($this->project, 'app', ['php', 'artisan', 'migrate', '--force'], ['DB_PASSWORD' => 'hunter2-secret'], '33:33', 'stdin', 600.0);

    $run = $this->runner->runs[0];
    expect($run['command'])->toBe([...COMPOSE_BASE, 'run', '--rm', '--no-TTY', '--no-deps', '--user', '33:33', '--env', 'DB_PASSWORD', 'app', 'php', 'artisan', 'migrate', '--force'])
        ->and(implode(' ', $run['command']))->not->toContain('hunter2')
        ->and($run['env']['DB_PASSWORD'])->toBe('hunter2-secret')
        ->and($run['input'])->toBe('stdin')
        ->and($run['timeout'])->toBe(600.0)
        ->and($outcome->exitCode)->toBe(3)
        ->and($outcome->successful())->toBeFalse();
});

it('streams a one-off with its own entrypoint into a file, with env values only in the environment', function (): void {
    $outcome = $this->docker->composeRunToFile($this->project, 'app', ['--format=custom'], '/srv/forms/backups/final/database.dump', ['PGPASSWORD' => 'hunter2-secret'], 'pg_dump', 3600.0);

    $run = $this->runner->runs[0];
    expect($run['command'])->toBe([...COMPOSE_BASE, 'run', '--rm', '--no-TTY', '--no-deps', '--entrypoint', 'pg_dump', '--env', 'PGPASSWORD', 'app', '--format=custom'])
        ->and(implode(' ', $run['command']))->not->toContain('hunter2')
        ->and($run['env']['PGPASSWORD'])->toBe('hunter2-secret')
        ->and($run['stdout_file'])->toBe('/srv/forms/backups/final/database.dump')
        ->and($run['timeout'])->toBe(3600.0)
        ->and($outcome->successful())->toBeTrue()
        ->and(fn () => $this->docker->composeRunToFile($this->project, 'app', [], '/x', [], 'sh -c'))->toThrow(InvalidInput::class);
});

it('lets a one-off start its dependencies when asked', function (): void {
    $this->docker->composeRun($this->project, 'app', ['true'], withDependencies: true);

    expect($this->runner->lastCommand())->not->toContain('--no-deps');
});

it('parses compose ps as NDJSON and as the older JSON array', function (string $stdout): void {
    $this->runner->push(new ProcessOutcome(0, $stdout));

    $containers = $this->docker->composePs($this->project);

    expect($this->runner->lastCommand())->toBe([...COMPOSE_BASE, 'ps', '--all', '--format', 'json'])
        ->and($containers)->toHaveCount(2)
        ->and($containers[0]->service())->toBe('app')
        ->and($containers[0]->running())->toBeTrue()
        ->and($containers[0]->health)->toBe('healthy')
        ->and($containers[1]->running())->toBeFalse()
        ->and($containers[1]->health)->toBeNull();
})->with([
    'ndjson' => ['{"ID":"1","Name":"fsa-app-1","State":"running","Health":"healthy","Labels":"com.docker.compose.service=app,app.form-schema.instance=u"}'."\n".'{"ID":"2","Name":"fsa-queue-1","State":"exited","Health":"","Labels":"com.docker.compose.service=queue"}'],
    'array' => ['[{"ID":"1","Name":"fsa-app-1","State":"running","Health":"healthy","Labels":"com.docker.compose.service=app"},{"ID":"2","Name":"fsa-queue-1","State":"exited","Health":"","Labels":"com.docker.compose.service=queue"}]'],
]);

it('returns the normalized compose model and reports an invalid file with its stderr', function (): void {
    $this->runner->push(new ProcessOutcome(0, '{"name":"fsa-forms-ab12","services":{"app":{}}}'), new ProcessOutcome(15, '', "service \"app\" refers to undefined volume x\n"));

    expect($this->docker->composeConfig($this->project)['services'])->toBe(['app' => []]);

    try {
        $this->docker->composeConfig($this->project);
        $this->fail('should throw');
    } catch (DockerCommandFailed $e) {
        expect($e->getMessage())->toBe('`docker compose config` exited with 15.')
            ->and($e->data['stderr'])->toContain('undefined volume')
            ->and($e->exitCode)->toBe(ExitCode::InternalError);
    }
});

it('pulls by digest and inspects images, answering null for unknown ones', function (): void {
    $ref = 'ghcr.io/x/app@sha256:'.str_repeat('1', 64);
    $this->runner->push(
        new ProcessOutcome(0),
        new ProcessOutcome(0, json_encode(['Id' => 'sha256:abc', 'RepoDigests' => [$ref], 'Architecture' => 'arm64', 'Os' => 'linux', 'Config' => ['Labels' => ['org.opencontainers.image.version' => '2.0.0']]])),
        new ProcessOutcome(1, '', 'Error response from daemon: No such image: x'),
    );

    $this->docker->pullImage($ref);
    $image = $this->docker->inspectImage($ref);

    expect($this->runner->runs[0]['command'])->toBe(['docker', 'pull', '--quiet', $ref])
        ->and($this->runner->runs[0]['timeout'])->toBeNull()
        ->and($this->runner->runs[1]['command'])->toBe(['docker', 'image', 'inspect', '--format', '{{json .}}', $ref])
        ->and($image?->hasDigest($ref))->toBeTrue()
        ->and($image?->architecture)->toBe('arm64')
        ->and($image?->labels['org.opencontainers.image.version'])->toBe('2.0.0')
        ->and($this->docker->inspectImage('x'))->toBeNull();
});

it('inspects a container for its ID and start time', function (): void {
    $this->runner->push(
        new ProcessOutcome(0, json_encode(['Id' => str_repeat('a', 64), 'Name' => '/fsa-ops', 'State' => ['Status' => 'running', 'Running' => true, 'StartedAt' => '2026-10-17T10:00:00.123456789Z', 'Health' => ['Status' => 'healthy']], 'Config' => ['Image' => 'app', 'Labels' => ['app.form-schema.role' => 'ops']]])),
        new ProcessOutcome(1, '', 'Error: No such container: gone'),
        new ProcessOutcome(1, '', 'Error response from daemon: something else'),
    );

    $container = $this->docker->inspectContainer('fsa-ops');

    expect($this->runner->runs[0]['command'])->toBe(['docker', 'container', 'inspect', '--format', '{{json .}}', 'fsa-ops'])
        ->and($container?->id)->toBe(str_repeat('a', 64))
        ->and($container?->name)->toBe('fsa-ops')
        ->and($container?->startedAt)->toBe('2026-10-17T10:00:00.123456789Z')
        ->and($container?->health)->toBe('healthy')
        ->and($container?->labels)->toBe(['app.form-schema.role' => 'ops'])
        ->and($this->docker->inspectContainer('gone'))->toBeNull()
        ->and(fn () => $this->docker->inspectContainer('x'))->toThrow(DockerCommandFailed::class);
});

it('lists containers, volumes and networks by label', function (): void {
    $this->runner->push(
        new ProcessOutcome(0, '{"ID":"'.str_repeat('b', 64).'","Names":"fsa-app-1","State":"running","Image":"app","Labels":"app.form-schema.instance=u1"}'),
        new ProcessOutcome(0, '{"Name":"fsa_pgdata","Driver":"local","Labels":"app.form-schema.instance=u1,com.docker.compose.project=fsa"}'),
        new ProcessOutcome(0, '{"ID":"n1","Name":"fsa_default","Labels":"app.form-schema.instance=u1"}'."\n"),
        new ProcessOutcome(0, ''),
    );
    $labels = ['app.form-schema.instance' => 'u1', 'app.form-schema.role' => null];

    $containers = $this->docker->containers($labels);
    $volumes = $this->docker->volumes($labels);
    $networks = $this->docker->networks($labels);

    $filters = ['--filter', 'label=app.form-schema.instance=u1', '--filter', 'label=app.form-schema.role'];
    expect($this->runner->runs[0]['command'])->toBe(['docker', 'container', 'ls', '--no-trunc', '--format', '{{json .}}', '--all', ...$filters])
        ->and($this->runner->runs[1]['command'])->toBe(['docker', 'volume', 'ls', '--format', '{{json .}}', ...$filters])
        ->and($this->runner->runs[2]['command'])->toBe(['docker', 'network', 'ls', '--no-trunc', '--format', '{{json .}}', ...$filters])
        ->and($containers[0]->name)->toBe('fsa-app-1')
        ->and($volumes)->toEqual([new DockerResource('volume', 'fsa_pgdata', ['app.form-schema.instance' => 'u1', 'com.docker.compose.project' => 'fsa'])])
        ->and($networks[0]->name)->toBe('fsa_default')
        ->and($this->docker->containers([], all: false))->toBe([])
        ->and($this->runner->lastCommand())->toBe(['docker', 'container', 'ls', '--no-trunc', '--format', '{{json .}}']);
});

it('builds docker run from a container spec with environment values out of argv', function (): void {
    $spec = new ContainerSpec(
        image: 'ghcr.io/x/app@sha256:'.str_repeat('1', 64),
        command: ['upgrade', '--supervise'],
        labels: ['app.form-schema.instance' => 'u1'],
        env: ['HOME' => '/tmp', 'SMOKE_TOKEN' => 'tok-secret'],
        volumes: ['/var/run/docker.sock:/var/run/docker.sock', '/srv/forms:/srv/forms'],
        ports: ['127.0.0.1:8080:80'],
        network: 'fsa_default',
        user: '1000:1000',
        entrypoint: '/usr/local/bin/fsa',
        workdir: '/srv/forms',
        groupAdd: ['998'],
    );

    $this->docker->runContainer($spec->detachedAs('fsa-forms-ab12-supervisor', ['app.form-schema.role' => 'supervisor']), 'in', 30.0);

    $run = $this->runner->runs[0];
    expect($run['command'])->toBe([
        'docker', 'run', '--detach', '--interactive', '--rm', '--name', 'fsa-forms-ab12-supervisor', '--restart', 'no',
        '--network', 'fsa_default', '--user', '1000:1000', '--entrypoint', '/usr/local/bin/fsa', '--workdir', '/srv/forms',
        '--label', 'app.form-schema.instance=u1', '--label', 'app.form-schema.role=supervisor',
        '--volume', '/var/run/docker.sock:/var/run/docker.sock', '--volume', '/srv/forms:/srv/forms',
        '--publish', '127.0.0.1:8080:80', '--group-add', '998',
        '--env', 'HOME', '--env', 'SMOKE_TOKEN',
        'ghcr.io/x/app@sha256:'.str_repeat('1', 64), 'upgrade', '--supervise',
    ])->and(implode(' ', $run['command']))->not->toContain('tok-secret')
        ->and($run['env']['SMOKE_TOKEN'])->toBe('tok-secret')
        ->and($run['timeout'])->toBe(30.0);
});

it('removes single resources', function (Closure $call, array $args): void {
    $call($this->docker);

    expect($this->runner->lastCommand())->toBe(['docker', ...$args]);
})->with([
    'container' => [fn ($d) => $d->removeContainer('abc'), ['container', 'rm', '--force', 'abc']],
    'container gently' => [fn ($d) => $d->removeContainer('abc', false), ['container', 'rm', 'abc']],
    'volume' => [fn ($d) => $d->removeVolume('fsa_pgdata'), ['volume', 'rm', 'fsa_pgdata']],
    'network' => [fn ($d) => $d->removeNetwork('fsa_default'), ['network', 'rm', 'fsa_default']],
]);

it('reports client, daemon and Compose versions', function (): void {
    $this->runner->push(new ProcessOutcome(0, '{"Client":{"Version":"29.9.0"},"Server":{"Version":"29.9.1"}}'), new ProcessOutcome(0, "v5.6.0\n"));

    $versions = $this->docker->versions();

    expect([$versions->client, $versions->server, $versions->compose])->toBe(['29.9.0', '29.9.1', '5.6.0'])
        ->and($this->runner->runs[1]['command'])->toBe(['docker', 'compose', 'version', '--short']);
});

it('maps an unreachable daemon or a missing CLI to exit 10', function (ProcessOutcome $outcome): void {
    $this->runner->push($outcome);

    try {
        $this->docker->versions();
        $this->fail('should throw');
    } catch (DockerUnavailable $e) {
        expect($e->exitCode)->toBe(ExitCode::DockerUnavailable)->and($e->retryable)->toBeTrue();
    }
})->with([
    'daemon down' => [new ProcessOutcome(1, '', 'Cannot connect to the Docker daemon at unix:///var/run/docker.sock. Is the docker daemon running?')],
    'socket permission' => [new ProcessOutcome(1, '', 'permission denied while trying to connect to the Docker daemon socket')],
    'missing binary' => [ProcessOutcome::notStarted('proc_open(): posix_spawn() failed: No such file or directory')],
]);

it('rejects service and environment names that are not plain names', function (Closure $call): void {
    expect(fn () => $call($this->docker, $this->project))->toThrow(InvalidInput::class)
        ->and($this->runner->runs)->toBe([]);
})->with([
    'service flag' => [fn ($d, $p) => $d->composeUp($p, ['--privileged'])],
    'env name' => [fn ($d, $p) => $d->composeRun($p, 'app', [], ['A=B' => 'x'])],
]);

it('rejects invalid compose project names and unexpected output', function (): void {
    $this->runner->push(new ProcessOutcome(0, 'not json'), new ProcessOutcome(0, '[1'));

    expect(fn () => new ComposeProject('Bad Name', '/x', '/'))->toThrow(InstanceException::class)
        ->and(fn () => $this->docker->composeConfig($this->project))->toThrow(InstanceException::class, 'Unexpected output')
        ->and(fn () => $this->docker->composePs($this->project))->toThrow(InstanceException::class, 'Unexpected output');
});

it('reports a timed-out call as retryable', function (): void {
    $this->runner->push(new ProcessOutcome(124, '', 'partial', true));

    try {
        $this->docker->composeRm($this->project);
    } catch (DockerCommandFailed $e) {
        expect($e->getMessage())->toContain('timed out')->and($e->retryable)->toBeTrue();
    }
});

it('derives the compose project of an instance from its state', function (): void {
    $directory = TemporaryInstance::withState();

    $project = ComposeProject::forInstance($directory->state(), $directory);

    expect($project->name)->toBe($directory->state()->project())
        ->and($project->file)->toBe($directory->root.'/compose.yaml')
        ->and($project->directory)->toBe($directory->root);
});

it('streams a real process\'s stdout into a new 0600 file instead of memory, and never over an existing file', function (): void {
    $directory = TemporaryInstance::path();
    mkdir($directory);
    $runner = new SymfonyCommandRunner;
    $script = 'for ($i = 0; $i < 64; $i++) { echo str_repeat(chr(65 + $i % 26), 65536); } fwrite(STDERR, "done");';

    $outcome = $runner->run([PHP_BINARY, '-r', $script], [], null, 30, $directory.'/out.bin');
    file_put_contents($directory.'/taken', 'keep');
    $refused = $runner->run([PHP_BINARY, '-r', 'echo "x";'], [], null, 30, $directory.'/taken');
    $missing = $runner->run([PHP_BINARY, '-r', 'echo "x";'], [], null, 30, $directory.'/no-such-dir/out.bin');

    expect($outcome->exitCode)->toBe(0)
        ->and($outcome->stdout)->toBe('')
        ->and($outcome->stderr)->toBe('done')
        ->and(filesize($directory.'/out.bin'))->toBe(64 * 65536)
        ->and(substr((string) file_get_contents($directory.'/out.bin'), 65536, 3))->toBe('BBB')
        ->and(substr(sprintf('%o', fileperms($directory.'/out.bin')), -3))->toBe('600')
        ->and($refused->exitCode)->toBe(1)
        ->and($refused->started)->toBeTrue()
        ->and($refused->stderr)->toContain('cannot be created')
        ->and(file_get_contents($directory.'/taken'))->toBe('keep')
        ->and($missing->successful())->toBeFalse();
});

it('reports a timeout while streaming to a file', function (): void {
    $directory = TemporaryInstance::path();
    mkdir($directory);

    $slow = new SymfonyCommandRunner()->run([PHP_BINARY, '-r', 'echo "partial"; flush(); sleep(5);'], [], null, 0.5, $directory.'/out.bin');

    expect($slow->timedOut)->toBeTrue()->and($slow->successful())->toBeFalse();
});

it('runs real processes without a shell, with timeouts and start failures', function (): void {
    $runner = new SymfonyCommandRunner;

    $ok = $runner->run([PHP_BINARY, '-r', 'fwrite(STDERR, "e"); echo getenv("FSA_X"), "|", var_export(getenv("FSA_GONE"), true), "|", stream_get_contents(STDIN); exit(3);'], ['FSA_X' => 'a b$c', 'FSA_GONE' => false], 'in');
    $slow = $runner->run([PHP_BINARY, '-r', 'sleep(5);'], [], null, 0.5);
    $missing = $runner->run(['/nonexistent/docker-'.bin2hex(random_bytes(3))]);

    expect($ok->exitCode)->toBe(3)
        ->and($ok->stdout)->toBe('a b$c|false|in')
        ->and($ok->stderr)->toBe('e')
        ->and($ok->started)->toBeTrue()
        ->and($slow->timedOut)->toBeTrue()
        ->and($slow->successful())->toBeFalse()
        ->and($missing->started)->toBeFalse()
        ->and($missing->exitCode)->toBe(127);
});
