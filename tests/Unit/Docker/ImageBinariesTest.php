<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;
use Tests\Support\Docker\DockerfileStages;

/*
 * docker/app/binaries.json, the inventory of third-party software the app image adds (ADR 0071), and
 * docker/app/Dockerfile agree; scripts/check-licenses.php checks every license in it. The image contract journey
 * checks that every listed path and license file exists in the built image.
 */

/** @return array<string, array<string, mixed>> name => entry */
function imageBinaries(): array
{
    $data = json_decode((string) file_get_contents(base_path('docker/app/binaries.json')), true, flags: JSON_THROW_ON_ERROR);

    return array_column($data['binaries'], null, 'name');
}

function imageDockerfile(): DockerfileStages
{
    return DockerfileStages::fromFile(base_path('docker/app/Dockerfile'));
}

it('describes every entry with version, origin, source, license, paths and license files', function (): void {
    foreach (imageBinaries() as $name => $binary) {
        expect($binary)->toHaveKeys(['component', 'version', 'origin', 'source', 'license', 'paths', 'license_files'], $name)
            ->and($binary['origin'])->toBeIn(['release', 'source', 'apt', 'pecl'], $name)
            ->and($binary['paths'])->not->toBeEmpty($name)
            ->and($binary['license_files'])->not->toBeEmpty($name);

        foreach ([...$binary['paths'], ...$binary['license_files']] as $path) {
            expect($path)->toMatch('#^/[\w./*+-]+$#', $name);
        }
    }

    expect(array_keys(imageBinaries()))->not->toContain('cosign');
});

it('pins the Docker CLI release that the downloads stages fetch', function (): void {
    $docker = imageBinaries()['docker'];

    foreach (['amd64' => 'x86_64', 'arm64' => 'aarch64'] as $arch => $token) {
        expect(imageDockerfile()->stage('downloads-'.$arch))
            ->toContain("{$docker['source']}{$token}/docker-{$docker['version']}.tgz");
    }
});

it('builds every source entry from its tag, verified by commit, with the upstream version string', function (string $name): void {
    $binary = imageBinaries()[$name];
    $text = imageDockerfile()->text;

    expect($binary['commit'])->toMatch('/^[0-9a-f]{40}$/')
        ->and($text)->toContain("clone --quiet --depth 1 --branch v{$binary['version']} {$binary['source']} .")
        ->and($text)->toContain("test \"\$(git rev-parse HEAD)\" = {$binary['commit']}")
        ->and($text)->toMatch('/-X \S+\.Version=v'.preg_quote($binary['version'], '/').'"/');
})->with(['docker-compose', 'age']);

it('lists everything the Dockerfile clones, downloads or installs from PGDG and PECL', function (): void {
    $dockerfile = imageDockerfile();
    preg_match_all('#clone --quiet --depth 1 --branch v\S+ (\S+) \.#', $dockerfile->text, $clones);
    $sources = array_column(imageBinaries(), 'source');

    expect($clones[1])->toHaveCount(2);
    foreach ($clones[1] as $url) {
        expect($sources)->toContain($url);
    }

    preg_match_all('#^ADD --checksum=sha256:[0-9a-f]{64} (\S+)#m', $dockerfile->stage('downloads-amd64'), $downloads);
    expect($downloads[1])->toHaveCount(1)
        ->and($dockerfile->stage('runtime-build'))->toMatch('/apt-get install [^\n]*postgresql-client-16/')
        ->and(imageBinaries()['postgresql-client-16']['source'])->toBe('https://apt.postgresql.org/pub/repos/apt')
        ->and($dockerfile->stage('runtime-build'))->toMatch('/install-php-extensions [^\n]*\bredis\b/')
        ->and(imageBinaries())->toHaveKey('phpredis');
});

it('ships the license and notice files the tools stage collects for the binaries it adds', function (): void {
    $tools = imageDockerfile()->stage('tools');

    foreach (imageBinaries() as $name => $binary) {
        if (! in_array($binary['origin'], ['release', 'source'], true)) {
            continue;
        }

        foreach ($binary['license_files'] as $file) {
            expect($file)->toStartWith('/usr/share/doc/', $name)
                ->and($tools)->toContain('/out/doc/'.basename(dirname((string) $file)).'/', $name);
        }
    }

    expect(imageDockerfile()->finalStage())->toContain('COPY --from=tools /out/doc/ /usr/share/doc/');
});

it('passes the license check, which refuses a disallowed or incomplete entry', function (): void {
    $check = fn (string ...$arguments): Process => tap(new Process(['php', base_path('scripts/check-licenses.php'), '--binaries-only', ...$arguments]))->run();
    $inventory = tempnam(sys_get_temp_dir(), 'binaries');
    $entry = ['name' => 'x', 'version' => '1', 'source' => 'https://example.org', 'paths' => ['/x'], 'license_files' => ['/x.txt']];

    try {
        $ok = $check();
        file_put_contents($inventory, json_encode(['binaries' => [[...$entry, 'license' => 'SSPL-1.0']]]));
        $disallowed = $check($inventory);
        file_put_contents($inventory, json_encode(['binaries' => [[...$entry, 'license' => 'MIT', 'license_files' => []]]]));
        $incomplete = $check($inventory);

        expect($ok->getExitCode())->toBe(0, $ok->getErrorOutput())
            ->and($ok->getOutput())->toContain('Image binary licenses OK ('.count(imageBinaries()).' components)')
            ->and($disallowed->getExitCode())->toBe(1)
            ->and($disallowed->getErrorOutput())->toContain('x 1: SSPL-1.0')
            ->and($incomplete->getExitCode())->toBe(1)
            ->and($incomplete->getErrorOutput())->toContain('needs name, version, source, license, paths and license_files');
    } finally {
        unlink($inventory);
    }
});
