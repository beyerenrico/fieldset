<?php

declare(strict_types=1);

use App\Domain\Instance\Release\ManifestVerifier;
use Tests\Support\Docker\DistributionScripts;
use Tests\Support\Release\ReleaseFixtures;

/*
 * 2.1.1 U3 at install (R1, R7, R24, R27; KTD2, KTD12): install.sh hands over through the shim, whose measuring container
 * is the first container that mounts the instance directory, read-only. A directory a failed 2.1.0 install left (marker,
 * no state, root-owned entries) gets its root-owned entries back from a root container before `fieldset install` runs:
 * owners only, on one file system, never of or through a symlink, never of a file with a second hard link. The leftover
 * fix runs its real find expression against the test tree through STUB_RECLAIM_HOOK, with the test user standing in for
 * root (tests cannot create root-owned files).
 */

beforeEach(function (): void {
    $this->scripts = new DistributionScripts;
    $this->release = $this->scripts->root.'/release';
    mkdir($this->release);
    mkdir($this->scripts->root.'/home');
    ReleaseFixtures::writeSignedManifest($this->release);
    rename($this->release.'/'.ManifestVerifier::BUNDLE, $this->release.'/release-manifest.json.sigstore.json');
    $this->dir = realpath($this->scripts->root).'/instance';
    $this->image = 'ghcr.io/beyerenrico/fieldset@'.ReleaseFixtures::APP_DIGEST;
    $this->install = DistributionScripts::repository().'/distribution/install.sh';
    $this->uid = (string) posix_getuid();
    $this->gid = (string) posix_getgid();
    $this->wsl = ['STUB_RELEASE_DIR' => $this->release, 'STUB_UNAME' => 'Linux', 'STUB_DOCKER_INFO' => 'Docker Desktop|docker-desktop|[]', 'STUB_MEASURE_OWNER' => $this->uid];
});

afterEach(fn () => $this->scripts->cleanUp());

/** @return list<list<string>> docker calls that bind-mount the given directory (any mode), in call order */
function reclaimMounting(DistributionScripts $scripts, string $dir): array
{
    return array_values(array_filter($scripts->calls('docker'), static fn (array $call): bool => array_any($call, fn (string $arg, $index) => $arg === '-v' && str_starts_with($call[$index + 1] ?? '', $dir.':'))));
}

/** The leftover of a failed 2.1.0 install on WSL: marker, no state, entries root wrote (here: the test user's). */
function reclaimLeftover(string $dir, string $outside): void
{
    mkdir($dir.'/locks', 0700, true);
    mkdir($dir.'/operations/history', 0700, true);
    file_put_contents($dir.'/.fieldset-instance', "{\"schema_version\":\"1.0\",\"kind\":\"fieldset instance\"}\n");
    file_put_contents($dir.'/operations/history/install-1.json', '{}');
    file_put_contents($outside, 'not fieldset\'s');
    symlink($outside, $dir.'/locks/link-out');
    file_put_contents($dir.'/locks/install.lock', '');
    file_put_contents($dir.'/locks/shared', '');
    link($dir.'/locks/shared', $dir.'/locks/shared-link');
}

/** A hook that runs the leftover fix's find expression on the host: the test user instead of root, chown recorded. */
function reclaimHook(DistributionScripts $scripts, string $image): string
{
    $scripts->writeExecutable('chown-recorder', "#!/bin/sh\nfor a in \"\$@\"; do printf '%s\\n' \"\$a\"; done >> \"\$STUB_ROOT/chowned\"\n");

    return $scripts->writeExecutable('reclaim-hook', '#!/bin/sh
while [ "$1" != '.escapeshellarg($image).' ]; do shift; done
shift
n=$# prev=
while [ "$n" -gt 0 ]; do
	a=$1
	shift
	n=$((n - 1))
	case $prev:$a in -uid:0) a=$(id -u) ;; *:chown) a=$STUB_ROOT/bin/chown-recorder ;; esac
	prev=$a
	set -- "$@" "$a"
done
exec find "$@"
');
}

it('measures in the first container that mounts the instance directory, read-only, before fieldset install (R1, R7)', function (array $env, bool $root): void {
    $process = $this->scripts->run($this->install, ['--dir', $this->dir, '--json'], [...$this->wsl, ...$env]);
    $mounting = reclaimMounting($this->scripts, $this->dir);

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
        ->and($mounting)->toHaveCount(2)
        ->and($mounting[0])->toBe(['docker', ...$this->scripts->measureRuns()[0]])
        ->and($mounting[0])->toContain("{$this->dir}:{$this->dir}:ro")
        ->and($mounting[0])->not->toContain("{$this->dir}:{$this->dir}")
        ->and($mounting[1])->toBe(['docker', ...$this->scripts->opsRuns()[0]])
        ->and($this->scripts->reclaimRuns())->toBe([])
        ->and($this->scripts->opsEnvironment())->toMatchArray(['FIELDSET_CONTAINER_UID' => $root ? '0' : $this->uid, 'FIELDSET_OWNER_SOURCE' => 'measured', 'FIELDSET_LAUNCHER' => 'install.sh']);
})->with([
    'Docker Engine on Linux: IDs pass through' => [['STUB_DOCKER_INFO' => 'Ubuntu 24.04 LTS|host|[]'], false],
    'macOS: root-mapped' => [['STUB_UNAME' => 'Darwin', 'STUB_MEASURE_OWNER' => '0'], true],
]);

it('stops install when containers see the files as a third UID, naming both, before fieldset install runs (R27)', function (): void {
    $process = $this->scripts->run($this->install, ['--dir', $this->dir, '--json'], [...$this->wsl, 'STUB_DOCKER_INFO' => 'Ubuntu 24.04 LTS|host|[]', 'STUB_MEASURE_OWNER' => '100000']);
    $document = json_decode($process->getOutput(), true);

    expect($process->getExitCode())->toBe(3, $process->getErrorOutput())
        ->and($document['command'])->toBe('install')
        ->and($document['errors'][0]['code'])->toBe('owner_mapping_unknown')
        ->and($document['errors'][0]['message'])->toContain("UID {$this->uid}", 'UID 100000')
        ->and($this->scripts->opsRuns())->toBe([])
        ->and(glob($this->dir.'/.fieldset-nonce-*'))->toBe([]);
});

it('gives the root-owned leftovers of a failed 2.1.0 install back to the measured owner, then installs (covers R24, KTD12)', function (): void {
    $this->scripts->wsl();
    mkdir($this->dir, 0700);
    reclaimLeftover($this->dir, $this->scripts->root.'/home/outside.txt');
    $before = [fileowner($this->scripts->root.'/home/outside.txt'), filemtime($this->scripts->root.'/home/outside.txt')];

    $process = $this->scripts->run($this->install, ['--dir', $this->dir, '--json', '--yes'], [...$this->wsl, 'STUB_RECLAIM_HOOK' => reclaimHook($this->scripts, $this->image)]);
    $reclaim = $this->scripts->reclaimRuns()[0] ?? [];
    $chowned = explode("\n", trim((string) @file_get_contents($this->scripts->root.'/chowned')));
    $mounting = reclaimMounting($this->scripts, $this->dir);

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
        ->and(array_map(ownerEntrypoint(...), $mounting))->toBe(['stat', 'find', 'fieldset'])
        ->and(array_slice($reclaim, 0, 9))->toBe(['run', '--rm', '--network', 'none', '--label', 'app.fieldset.role=owner', '--user', '0:0', '--entrypoint'])
        ->and(array_slice($reclaim, array_search('--entrypoint', $reclaim, true)))->toBe([
            '--entrypoint', 'find', '-v', "{$this->dir}:{$this->dir}", $this->image,
            $this->dir, '-xdev', '-uid', '0', '!', '-type', 'l', '(', '-type', 'd', '-o', '-links', '1', ')', '-exec', 'chown', '-h', "{$this->uid}:{$this->gid}", '{}', '+',
        ])
        ->and(array_slice($chowned, 0, 2))->toBe(['-h', "{$this->uid}:{$this->gid}"])
        ->and($chowned)->toContain($this->dir, $this->dir.'/locks', $this->dir.'/locks/install.lock', $this->dir.'/operations', $this->dir.'/operations/history', $this->dir.'/operations/history/install-1.json', $this->dir.'/.fieldset-instance')
        ->and($chowned)->not->toContain($this->dir.'/locks/link-out', $this->scripts->root.'/home/outside.txt', $this->dir.'/locks/shared', $this->dir.'/locks/shared-link')
        ->and([fileowner($this->scripts->root.'/home/outside.txt'), filemtime($this->scripts->root.'/home/outside.txt')])->toBe($before);
});

/** @param  list<string>  $call */
function ownerEntrypoint(array $call): string
{
    return $call[array_search('--entrypoint', $call, true) + 1];
}

/** @param  list<string>  $run  @return string|null the value after $flag in a docker run */
function reclaimFlag(array $run, string $flag): ?string
{
    $index = array_search($flag, $run, true);

    return $index === false ? null : $run[$index + 1];
}

it('leaves a directory alone without leftovers to fix: fresh, root-mapped, unmeasured, or with a state', function (array $env, bool $wsl, bool $leftover, bool $state): void {
    if ($wsl) {
        $this->scripts->wsl();
    }
    if (! $leftover) {
        $process = $this->scripts->run($this->install, ['--dir', $this->dir, '--json'], [...$this->wsl, ...$env]);
        expect($process->getExitCode())->toBe(0, $process->getErrorOutput())->and($this->scripts->reclaimRuns())->toBe([]);

        return;
    }
    mkdir($this->dir, 0700);
    reclaimLeftover($this->dir, $this->scripts->root.'/home/outside.txt');
    if ($state) {
        file_put_contents($this->dir.'/state.json', '{}');
    }

    $process = $this->scripts->run($this->install, ['--dir', $this->dir, '--json'], [...$this->wsl, ...$env]);

    expect($this->scripts->reclaimRuns())->toBe([])
        ->and($this->scripts->measureRuns())->toHaveCount(1)
        ->and($process->getExitCode())->toBe(0, $process->getErrorOutput());
})->with([
    'a fresh directory on WSL 2' => [[], true, false, false],
    'macOS: root maps to the host user' => [['STUB_UNAME' => 'Darwin', 'STUB_MEASURE_OWNER' => '0'], false, true, false],
    'WSL 2, the measurement could not run' => [['STUB_MEASURE_OWNER' => false], true, true, false],
    'WSL 2, a state exists' => [[], true, true, true],
]);

it('stops install before fieldset install when the leftovers cannot be given back (exit 3)', function (): void {
    $this->scripts->wsl();
    mkdir($this->dir, 0700);
    reclaimLeftover($this->dir, $this->scripts->root.'/home/outside.txt');

    $process = $this->scripts->run($this->install, ['--dir', $this->dir, '--json'], [...$this->wsl, 'STUB_RECLAIM_EXIT' => '125', 'STUB_RECLAIM_STDERR' => 'docker: Error response from daemon: no space left']);
    $error = json_decode($process->getOutput(), true)['errors'][0] ?? [];

    expect($process->getExitCode())->toBe(3, $process->getErrorOutput())
        ->and($error['code'] ?? null)->toBe('instance_unwritable')
        ->and($error['message'])->toContain('no space left', "UID {$this->uid}")
        ->and($error['hint'])->toContain("sudo chown -R -h {$this->uid}:{$this->gid} {$this->dir}")
        ->and($this->scripts->opsRuns())->toBe([]);
});

it('records a deterministic owner when installed as root: container root, ops as 0:0 on every run', function (): void {
    $this->scripts->writeExecutable('id', "#!/bin/sh\ncase \"\$1\" in -u | -g) echo 0 ;; *) exec /usr/bin/id \"\$@\" ;; esac\n");
    $env = [...$this->wsl, 'STUB_DOCKER_INFO' => 'Ubuntu 24.04 LTS|host|[]', 'STUB_MEASURE_OWNER' => '0'];

    $owner = fn (): array => array_intersect_key($this->scripts->opsEnvironment(), array_flip(['FIELDSET_HOST_UID', 'FIELDSET_DOCKER_RUNTIME', 'FIELDSET_CONTAINER_UID', 'FIELDSET_OWNER_SOURCE', 'FIELDSET_SOCKET_GROUP']));
    $first = $this->scripts->run($this->install, ['--dir', $this->dir, '--json'], $env);
    $facts = $owner();
    $again = $this->scripts->run($this->install, ['--dir', $this->dir, '--json'], $env);
    $runs = $this->scripts->opsRuns();

    expect($first->getExitCode())->toBe(0, $first->getErrorOutput())
        ->and($again->getExitCode())->toBe(0, $again->getErrorOutput())
        ->and($facts)->toEqualCanonicalizing(['FIELDSET_HOST_UID' => '0', 'FIELDSET_DOCKER_RUNTIME' => 'rootful', 'FIELDSET_CONTAINER_UID' => '0', 'FIELDSET_OWNER_SOURCE' => 'measured', 'FIELDSET_SOCKET_GROUP' => ''])
        ->and($owner())->toEqualCanonicalizing($facts)
        ->and(array_map(fn (array $run): ?string => reclaimFlag($run, '--user'), $runs))->toBe(['0:0', '0:0'])
        ->and(array_map(fn (array $run): ?string => reclaimFlag($run, '--group-add'), $runs))->toBe([null, null])
        ->and($this->scripts->reclaimRuns())->toBe([]);
});
