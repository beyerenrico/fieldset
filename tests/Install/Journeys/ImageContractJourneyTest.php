<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;
use Tests\Support\Docker\TestImage;

/*
 * U1 / M9-T02 image contract on the built image (R1, R5, R6, E17, KTD8, KTD9, ADR 0065). Builds
 * `fsa-test-u1:latest` natively, or `fsa-test-u1:<arch>` with FSA_TEST_PLATFORM=linux/<arch> under emulation.
 * Skips only when Docker is unreachable; FSA_REQUIRE_DOCKER=1 turns that skip into a failure.
 */

beforeEach(function (): void {
    $reason = TestImage::dockerUnavailableReason();
    if ($reason !== null) {
        expect(getenv('FSA_REQUIRE_DOCKER'))->not->toBe('1', $reason);
        $this->markTestSkipped($reason.' (install journeys need a Docker engine)');
    }
});

it('runs as a non-root user and reports its version and labels', function (): void {
    $inspect = TestImage::mustRun(['docker', 'image', 'inspect', '--format', '{{json .Config}}', TestImage::reference()]);
    $config = json_decode($inspect->getOutput(), true);
    $id = TestImage::run(['--entrypoint', 'id'], ['-u']);
    $version = TestImage::run(['--entrypoint', 'php'], ['artisan', 'config:show', 'app.version']);

    expect($config['User'])->toBe('www-data')
        ->and(trim($id->getOutput()))->not->toBe('0')
        ->and($config['Labels'])->toMatchArray([
            'org.opencontainers.image.version' => TestImage::VERSION,
            'org.opencontainers.image.revision' => TestImage::revision(),
            'org.opencontainers.image.source' => 'https://github.com/beyerenrico/form-schema-app',
            'org.opencontainers.image.licenses' => 'AGPL-3.0-only',
        ])
        ->and($version->getExitCode())->toBe(0, $version->getErrorOutput())
        ->and($version->getOutput())->toContain(TestImage::VERSION);
});

it('carries the ops binaries, executable by the image user, and no cosign (KTD8, ADR 0071)', function (): void {
    $script = <<<'SH'
        set -e
        docker --version
        docker compose version
        pg_dump --version
        pg_restore --version
        age --version
        age-keygen --version
        age-plugin-batchpass --version
        printf 'round trip' | AGE_PASSPHRASE=u1 AGE_PASSPHRASE_WORK_FACTOR=10 age -e -j batchpass \
          | AGE_PASSPHRASE=u1 age -d -j batchpass
        echo
        if command -v cosign; then echo "cosign present"; fi
        SH;
    $process = TestImage::run(['--entrypoint', 'sh', '-e', 'HOME=/tmp'], ['-c', $script]);
    $output = $process->getOutput();

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput().$output)
        ->and($output)->toMatch('/^Docker version 29\.9\.\d+/m')
        ->and($output)->toMatch('/^Docker Compose version v5\.6\.0$/m')
        ->and($output)->toMatch('/^pg_dump \(PostgreSQL\) 16\.\d+/m')
        ->and($output)->toMatch('/^pg_restore \(PostgreSQL\) 16\.\d+/m')
        ->and(preg_match_all('/^v1\.3\.2$/m', $output))->toBe(3)
        ->and($output)->toContain('round trip')
        ->and($output)->not->toContain('cosign');
});

it('holds every path and license file of docker/app/binaries.json (ADR 0071)', function (): void {
    $binaries = json_decode((string) file_get_contents(TestImage::repository().'/docker/app/binaries.json'), true)['binaries'];
    $paths = array_merge(...array_map(fn (array $binary): array => [...$binary['paths'], ...$binary['license_files']], $binaries));
    $checks = array_map(function (string $path): string {
        expect($path)->toMatch('#^/[\w./*+-]+$#');

        // Unquoted on purpose: an entry may be a glob (libpq's multiarch directory, the PHP extension directory).
        return "set -- {$path}; [ -e \"\$1\" ] || echo \"missing: {$path}\"";
    }, $paths);
    $process = TestImage::run(['--entrypoint', 'sh'], ['-c', implode("\n", [...$checks, 'true'])]);

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
        ->and(trim($process->getOutput()))->toBe('');
});

it('ships no tests, agent docs or composer, but keeps the spec files the SDK reads (R5)', function (): void {
    $script = <<<'SH'
        cd /var/www/html
        for path in tests docs scripts .github .git .env node_modules packages/fieldset-php/tests \
          packages/fieldset-js spec/conformance storage/logs/laravel.log /usr/bin/composer; do
          [ -e "$path" ] && echo "present: $path"
        done
        command -v composer && echo "present: composer on PATH"
        for path in spec/form-definition/1.0/meta-schema.json spec/form-definition/1.0/roles.json \
          spec/templates/index.json vendor/autoload.php public/build/manifest.json public/app/index.html; do
          [ -f "$path" ] || echo "missing: $path"
        done
        true
        SH;
    $process = TestImage::run(['--entrypoint', 'sh'], ['-c', $script]);

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
        ->and(trim($process->getOutput()))->toBe('');
});

it('starts the app role without permission errors in its logs (R6)', function (): void {
    $name = 'fsa-test-u1-app-'.bin2hex(random_bytes(4));
    $platform = TestImage::platform();
    TestImage::mustRun([
        'docker', 'run', '-d', '--name', $name, ...($platform === null ? [] : ['--platform', $platform]),
        '-e', 'APP_KEY=base64:'.base64_encode(random_bytes(32)), '-e', 'DB_CONNECTION=sqlite',
        '-e', 'DB_DATABASE=:memory:', '-e', 'CACHE_STORE=array', '-e', 'SESSION_DRIVER=array',
        TestImage::reference(),
    ]);

    try {
        $status = '000';
        for ($attempt = 0; $attempt < 90 && $status === '000'; $attempt++) {
            sleep(2);
            $probe = TestImage::mustRun(['docker', 'exec', $name, 'curl', '-s', '-o', '/dev/null', '-w', '%{http_code}', 'http://localhost:8080/up'], 60);
            $status = trim($probe->getOutput());
        }

        $logs = TestImage::mustRun(['docker', 'logs', $name]);
        $output = $logs->getOutput().$logs->getErrorOutput();
        file_put_contents(TestImage::logFile($name.'.log'), $output);

        expect($status)->toBe('200')
            ->and($output)->not->toMatch('/permission denied/i')
            ->and($output)->not->toContain('"level":"error"');
    } finally {
        new Process(['docker', 'rm', '-f', $name])->run();
    }
});

it('fits the image size budget of 1.0 GB uncompressed (E17)', function (): void {
    $inspect = TestImage::mustRun(['docker', 'image', 'inspect', '--format', '{{.Size}} {{.Architecture}}', TestImage::reference()]);
    [$size, $arch] = explode(' ', trim($inspect->getOutput()));
    file_put_contents(TestImage::logFile("fsa-test-u1-size-{$arch}.txt"), $size.PHP_EOL);

    expect((int) $size)->toBeLessThanOrEqual(1_000_000_000);
});

it('runs fsa as an arbitrary user without the app start-up (KTD2)', function (): void {
    $process = TestImage::run(['--user', '4242:4242', '-e', 'HOME=/tmp'], ['fsa']);

    expect($process->getOutput().$process->getErrorOutput())->toContain('"fsa" namespace')
        ->and($process->getErrorOutput())->not->toMatch('/permission denied/i');
});
