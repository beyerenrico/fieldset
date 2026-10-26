<?php

declare(strict_types=1);

use App\Domain\Instance\Console\FieldsetCommand;
use App\Domain\Instance\Docker\DockerRuntime;
use App\Domain\Instance\Lifecycle\LauncherScript;
use App\Domain\Instance\State\InstanceState;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\Support\Docker\DistributionScripts;
use Tests\Support\Instance\InstanceCommandFixture;

/*
 * 2.1.1 U15: the shim passes its own version (`fieldset_shim_version`) to the ops container as FIELDSET_LAUNCHER_VERSION
 * (KTD7), the value the image's ScriptRefresh compares with the version of its distribution/shim.sh. An instance whose
 * script is from 2.1.0 (no version) gets the image's script after one command, and the next run is the new script.
 */

const LAUNCHER_IMAGE = 'ghcr.io/beyerenrico/fieldset@sha256:2222222222222222222222222222222222222222222222222222222222222222';

/**
 * The SHA-256 of distribution/shim.sh per `fieldset_shim_version` (2.1.1 U8). ScriptRefresh hands the image's script only
 * to instances whose script declares a lower version, so a release that changes the shim without raising the version never
 * reaches upgraded instances. Older entries stay as a record of what each version shipped.
 */
const SHIM_SHA256 = [
    1 => '8eb05ccd5637a7f8e840d1bb325ecba8c7cf8acf2ebd0ef8a738be6cc1c9894a', // 2.1.1
];

beforeEach(function (): void {
    $this->scripts = new DistributionScripts;
    mkdir($this->scripts->root.'/home');
    $this->dir = $this->scripts->root.'/instance';
    $this->env = ['STUB_UNAME' => 'Linux', 'STUB_DOCKER_INFO' => 'Ubuntu 24.04 LTS|host|[]', 'STUB_MEASURE_OWNER' => (string) posix_getuid()];
    $this->version = LauncherScript::versionOf((string) file_get_contents(base_path('distribution/shim.sh')));
});

afterEach(fn () => $this->scripts->cleanUp());

/** A marked instance with a state whose owner was measured under $runtime (schema 1.1) and $script as its launcher. */
function launcherInstance(string $dir, string $script, DockerRuntime $runtime, ?int $socketGroup): InstanceCommandFixture
{
    $fixture = new InstanceCommandFixture($dir);
    $fixture->directory->saveState(InstanceState::create('3f1c2b9e-8a4d-4c1e-9b7a-2d5e6f708192', $dir, posix_getuid(), posix_getgid(), posix_getuid())
        ->withRelease('2.1.1', ['app' => LAUNCHER_IMAGE])
        ->withOwnerMeasurement($runtime, $socketGroup, CarbonImmutable::parse('2026-10-26T18:00:00Z')));
    file_put_contents($dir.'/fieldset', $script);
    chmod($dir.'/fieldset', 0700);

    return $fixture;
}

it('declares its version once, as a plain number the image reads', function (): void {
    $shim = (string) file_get_contents(base_path('distribution/shim.sh'));

    expect(preg_match_all('/^fieldset_shim_version=[1-9][0-9]*$/m', $shim))->toBe(1)
        ->and($this->version)->toBeGreaterThanOrEqual(1)
        ->and(LauncherScript::bundled()?->version)->toBe($this->version)
        ->and($this->scripts->source(base_path('distribution/shim.sh'), 'printf %s "$fieldset_shim_version"')->getOutput())->toBe((string) $this->version);
});

it('pins the shim to its declared version, so a release that changes the shim raises fieldset_shim_version (KTD7)', function (): void {
    $hash = hash_file('sha256', base_path('distribution/shim.sh'));
    $pinned = max(array_keys(SHIM_SHA256));
    $fix = 'Raise fieldset_shim_version in distribution/shim.sh when the shim changes for a release, then update the pinned hash '
        .'in SHIM_SHA256 (tests/Unit/Distribution/LauncherVersionTest.php); a change to a version no release has shipped yet only '
        .'updates its hash.';

    expect($this->version)->toBe($pinned, "fieldset_shim_version is {$this->version}, the newest pinned version {$pinned}. {$fix}")
        ->and(SHIM_SHA256[$this->version] ?? null)->toBe($hash, "distribution/shim.sh changed (SHA-256 {$hash}) but still declares fieldset_shim_version={$this->version}. {$fix}");
});

it('passes its version to the ops container (KTD7)', function (): void {
    launcherInstance($this->dir, (string) file_get_contents(base_path('distribution/shim.sh')), DockerRuntime::Rootful, null);

    $process = $this->scripts->run($this->dir.'/fieldset', ['status', '--json'], $this->env);
    $run = $this->scripts->opsRuns()[0];

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
        ->and($this->scripts->opsEnvironment())->toMatchArray(['FIELDSET_LAUNCHER' => 'shim', 'FIELDSET_LAUNCHER_VERSION' => (string) $this->version])
        ->and(implode(' ', $run))->toContain('-e FIELDSET_LAUNCHER_VERSION ');
});

it('runs the image\'s script after one command on an instance with a 2.1.0 script: WSL facts and the saved owner (R38)', function (): void {
    $fixture = launcherInstance($this->dir, "#!/bin/sh\n# fieldset 2.1.0: no launcher version, no WSL facts\n", DockerRuntime::Desktop, 0);
    $nonce = '.fieldset-nonce-'.str_repeat('b', 32);
    touch($this->dir.'/'.$nonce);
    $fixture->facts(['nonce' => $nonce, 'launcherVersion' => null, 'runtime' => DockerRuntime::Desktop]);
    app()->instance(FieldsetCommand::PROGRESS_OUTPUT, $progress = new BufferedOutput);

    Artisan::call('fieldset:logs', ['--json' => true, '--dir' => $this->dir]); // the first command, run by the 2.1.0 script
    unlink($this->dir.'/'.$nonce);
    $this->scripts->wsl();
    $process = $this->scripts->run($this->dir.'/fieldset', ['status', '--json'], [...$this->env, 'STUB_DOCKER_INFO' => 'Docker Desktop|docker-desktop|[]']);

    expect($progress->fetch())->toContain('the next command uses it')
        ->and($process->getExitCode())->toBe(0, $process->getErrorOutput())
        ->and($this->scripts->measureRuns())->toBe([])
        ->and($this->scripts->opsEnvironment())->toMatchArray([
            'FIELDSET_WSL' => '2', 'FIELDSET_WSL_BACKEND' => 'desktop', 'FIELDSET_LAUNCHER_VERSION' => (string) $this->version,
            'FIELDSET_OWNER_SOURCE' => 'saved', 'FIELDSET_CONTAINER_UID' => (string) posix_getuid(), 'FIELDSET_SOCKET_GROUP' => '0',
        ]);
});
