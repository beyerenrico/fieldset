<?php

declare(strict_types=1);

use App\Domain\Instance\Docker\CliDockerGateway;
use App\Domain\Instance\Docker\ContainerSpec;
use App\Domain\Instance\Docker\DockerInfo;
use App\Domain\Instance\Docker\ProcessOutcome;
use App\Domain\Instance\Doctor\Checks\RegistryCheck;
use App\Domain\Instance\Doctor\DoctorProbes;
use App\Domain\Instance\Doctor\PortProbe;
use App\Domain\Instance\HostFacts;
use App\Domain\Instance\InstanceException;
use App\Domain\Instance\InstanceServiceProvider;
use App\Domain\Instance\Lifecycle\OneOffResult;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\Support\Docker\ScriptHarness;
use Tests\Support\Instance\RecordingDockerGateway;
use Tests\Support\Instance\ScriptedCommandRunner;
use Tests\Support\Instance\TemporaryInstance;

/*
 * U5 / M9-T08: the pieces under the lifecycle commands: engine info, host facts of the install hand-over, the
 * port probe, one-off results, the probes seam, the fsa wrapper's --version mapping and the registration.
 */

afterEach(fn () => TemporaryInstance::cleanup());

it('reads the engine info: architecture, memory, rootless and clock', function (): void {
    $runner = new ScriptedCommandRunner;
    $runner->push(new ProcessOutcome(0, json_encode(['ServerVersion' => '29.2.1', 'OperatingSystem' => 'Docker Desktop', 'Architecture' => 'aarch64', 'MemTotal' => 8_000_000_000, 'NCPU' => 8, 'SecurityOptions' => ['name=seccomp,profile=builtin', 'name=rootless'], 'SystemTime' => '2026-10-18T10:00:00Z'])));

    $info = new CliDockerGateway($runner)->info();

    expect($runner->lastCommand())->toBe(['docker', 'info', '--format', '{{json .}}'])
        ->and($info)->toEqual(new DockerInfo('29.2.1', 'Docker Desktop', 'aarch64', 8_000_000_000, 8, true, '2026-10-18T10:00:00Z'))
        ->and($info->platformArchitecture())->toBe('arm64')
        ->and(new DockerInfo('1', architecture: 'x86_64')->platformArchitecture())->toBe('amd64')
        ->and(new DockerInfo('1', architecture: 'riscv64')->platformArchitecture())->toBe('riscv64')
        ->and(DockerInfo::fromJson(['MemTotal' => '1024'])->memoryBytes)->toBe(1024);
});

it('takes the verified release manifest and the registry mirror from install.sh', function (): void {
    $facts = HostFacts::fromEnvironment(['FSA_RELEASE_MANIFEST' => '/srv/fsa/releases/2.0.0/release-manifest.json', 'FSA_REGISTRY' => '127.0.0.1:5000', 'FSA_HOST_GID' => '20']);
    $bad = HostFacts::fromEnvironment(['FSA_RELEASE_MANIFEST' => 'relative.json', 'FSA_REGISTRY' => 'evil.example/path']);

    expect($facts->releaseManifest)->toBe('/srv/fsa/releases/2.0.0/release-manifest.json')
        ->and($facts->registry)->toBe('127.0.0.1:5000')
        ->and($facts->callerGid())->toBe(20)
        ->and($bad->releaseManifest)->toBeNull()
        ->and($bad->registry)->toBeNull()
        ->and(new HostFacts()->hasTerminal(true))->toBeTrue()
        ->and(new HostFacts(tty: false)->hasTerminal(true))->toBeFalse();
});

it('probes a port by publishing a throwaway container and reads the engine wording', function (string $stderr, ?bool $free): void {
    $docker = new RecordingDockerGateway;
    $docker->queueOutcome('runContainer', new ProcessOutcome($stderr === '' ? 0 : 125, '', $stderr));
    $probe = new PortProbe($docker);

    if ($free === null) {
        expect(fn () => $probe->isFree(8080, 'app@sha256:'.str_repeat('a', 64)))->toThrow(InstanceException::class, 'Cannot probe port 8080');

        return;
    }

    expect($probe->isFree(8080, 'app@sha256:'.str_repeat('a', 64)))->toBe($free)
        ->and($docker->called('runContainer')[0]['spec'])->toEqual(new ContainerSpec(image: 'app@sha256:'.str_repeat('a', 64), labels: ['app.form-schema.role' => 'port-probe'], ports: ['127.0.0.1:8080:8080'], entrypoint: 'true'));
})->with([
    'free' => ['', true],
    'another container' => ['Bind for 127.0.0.1:8080 failed: port is already allocated', false],
    'another process' => ['listen tcp4 127.0.0.1:8080: bind: address already in use', false],
    'Docker Desktop' => ['ports are not available: exposing port TCP 127.0.0.1:8080 -> 127.0.0.1:0', false],
    'something else' => ['no such image', null],
]);

it('reads the last JSON document of a one-off and keeps its secrets out of debug output', function (): void {
    $stdout = "Some notice\n".json_encode(['schema_version' => 1, 'status' => 'ok', 'data' => ['a' => 1], 'secrets' => [['name' => 'sign_in_link', 'value' => 'http://x/#t', 'expires_at' => 'garbage']], 'warnings' => [['code' => 'w']], 'errors' => []])."\n";

    $result = OneOffResult::fromOutcome(new ProcessOutcome(0, $stdout));
    $timeout = OneOffResult::fromOutcome(new ProcessOutcome(1, '', 'killed', true));

    expect($result->ok)->toBeTrue()
        ->and($result->data)->toBe(['a' => 1])
        ->and($result->secret('sign_in_link'))->toBe(['value' => 'http://x/#t', 'expires_at' => null])
        ->and($result->secret('other'))->toBeNull()
        ->and($result->hasWarning('w'))->toBeTrue()
        ->and(print_r($result, true))->not->toContain('http://x/#t')
        ->and($timeout->ok)->toBeFalse()
        ->and($timeout->errorCode())->toBe('one_off_timeout')
        ->and($timeout->errorHint())->toBeNull();
});

it('tells registries apart from Docker Hub references', function (string $image, string $registry): void {
    expect(RegistryCheck::registryOf($image))->toBe($registry);
})->with([
    ['ghcr.io/beyerenrico/form-schema-app@sha256:'.str_repeat('a', 64), 'ghcr.io'],
    ['127.0.0.1:5000/library/postgres@sha256:'.str_repeat('a', 64), '127.0.0.1:5000'],
    ['localhost/x@sha256:'.str_repeat('a', 64), 'localhost'],
    ['library/postgres@sha256:'.str_repeat('a', 64), 'docker.io'],
]);

it('probes the host through one seam', function (): void {
    Http::fake(['https://registry.test/v2/' => Http::response('', 401, ['Date' => 'Sun, 18 Oct 2026 10:00:00 GMT'])]);
    $probes = new DoctorProbes;
    $file = TemporaryInstance::path();
    file_put_contents($file, 'x');
    chmod($file, 0640);

    expect($probes->head('https://registry.test/v2/'))->toBe(['status' => 401, 'date' => 'Sun, 18 Oct 2026 10:00:00 GMT'])
        ->and($probes->stat($file))->toBe(['uid' => posix_getuid(), 'mode' => 0640])
        ->and($probes->stat($file.'-missing'))->toBeNull()
        ->and($probes->diskFree(sys_get_temp_dir()))->toBeFloat()
        ->and($probes->now())->toBeGreaterThan(1_700_000_000)
        ->and($probes->resolve('fsa-test.invalid', DNS_A))->toBe([]);

    Http::fake(fn () => throw new RuntimeException('offline'));
    expect($probes->head('https://unreachable.test/v2/'))->toBeNull();
});

it('passes fsa --version on as --release, because Symfony would answer it itself', function (array $arguments, string $call): void {
    $harness = new ScriptHarness(base_path());

    try {
        expect($harness->run('docker/app/fsa', $arguments))->toBe([$call]);
    } finally {
        $harness->cleanUp();
    }
})->with([
    'flag and value' => [['install', '--version', '2.0.0', '--json'], 'php /var/www/html/artisan fsa:install --release 2.0.0 --json'],
    'flag with =' => [['install', '--version=2.0.0-rc.1'], 'php /var/www/html/artisan fsa:install --release=2.0.0-rc.1'],
    'other flags untouched' => [['install', '--org', 'Acme Forms', '--dir', '/srv/fsa'], 'php /var/www/html/artisan fsa:install --org Acme Forms --dir /srv/fsa'],
    'other commands untouched' => [['version', '--version'], 'php /var/www/html/artisan fsa:version --version'],
    'nothing after --' => [['install', '--', '--version'], 'php /var/www/html/artisan fsa:install -- --version'],
]);

it('registers the lifecycle commands', function (): void {
    $commands = array_keys(Artisan::all());

    foreach (InstanceServiceProvider::COMMANDS as $class) {
        expect($commands)->toContain(app($class)->getName());
    }
    expect($commands)->toContain('fsa:install', 'fsa:doctor', 'fsa:status', 'fsa:logs', 'fsa:version', 'fsa:uninstall', 'fsa:login-link');
});
