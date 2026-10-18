<?php

declare(strict_types=1);

use App\Domain\Instance\Release\ManifestSchema;
use Tests\Support\Release\FakeRegistry;

/*
 * `release:version` and `release:manifest` (plan U7): the release workflow and the local harness call these;
 * they need no instance directory.
 */

/**
 * Writes release inputs whose pins live in the fake registry and returns [inputs path, app digest].
 *
 * @return array{0: string, 1: string}
 */
function writeFakeReleaseInputs(FakeRegistry $registry): array
{
    [$data, $appDigest] = $registry->releaseInputs();
    $path = tempnam(sys_get_temp_dir(), 'release-inputs');
    file_put_contents($path, json_encode($data));

    return [$path, $appDigest];
}

it('prints the channel and image tags of a version as JSON', function (): void {
    $this->artisan('release:version', ['version' => 'v2.0.0-rc.1'])
        ->expectsOutput('{"version":"2.0.0-rc.1","tag":"v2.0.0-rc.1","channel":"rc","prerelease":true,"image_tags":["2.0.0-rc.1"]}')
        ->assertSuccessful();

    $this->artisan('release:version', ['version' => '2.0.0'])
        ->expectsOutput('{"version":"2.0.0","tag":"v2.0.0","channel":"stable","prerelease":false,"image_tags":["2.0.0","2.0","2","latest"]}')
        ->assertSuccessful();
});

it('refuses an invalid version with exit code 2', function (): void {
    $this->artisan('release:version', ['version' => 'v2.0'])->assertExitCode(2);
});

it('writes a schema-valid manifest to a file', function (): void {
    $registry = new FakeRegistry(requireToken: true);
    [$inputs, $appDigest] = writeFakeReleaseInputs($registry);
    $registry->fake();
    $output = tempnam(sys_get_temp_dir(), 'manifest');
    putenv('RELEASE_REGISTRY_USERNAME=ci');
    putenv('RELEASE_REGISTRY_PASSWORD=token');

    try {
        $this->artisan('release:manifest', [
            'version' => 'v2.0.0-alpha.1', '--app-digest' => $appDigest, '--inputs' => $inputs,
            '--asset' => [base_path('distribution/release.json'), base_path('distribution/install.sh'), base_path('distribution/shim.sh')], '--output' => $output,
        ])->expectsOutputToContain('Release manifest for 2.0.0-alpha.1 (alpha) written to')->assertSuccessful();

        $json = (string) file_get_contents($output);
        expect(ManifestSchema::bundled()->errors($json))->toBe([])
            ->and(json_decode($json, true)['images']['app']['digest'])->toBe($appDigest)
            ->and(json_decode($json, true)['assets'])->toHaveKey('release.json')
            ->and(collect($registry->tokenRequests)->filter(fn ($request): bool => $request->hasHeader('Authorization'))->count())->toBeGreaterThan(0);
    } finally {
        putenv('RELEASE_REGISTRY_USERNAME');
        putenv('RELEASE_REGISTRY_PASSWORD');
        unlink($inputs);
        unlink($output);
    }
});

it('renders the stack file with --stack-file and lists it in the manifest', function (): void {
    $registry = new FakeRegistry;
    [$inputs, $appDigest] = writeFakeReleaseInputs($registry);
    $registry->fake();
    $output = tempnam(sys_get_temp_dir(), 'manifest');
    $stack = sys_get_temp_dir().'/compose.stack.yaml';
    @unlink($stack);

    try {
        $this->artisan('release:manifest', [
            'version' => '2.0.0-alpha.2', '--app-digest' => $appDigest, '--inputs' => $inputs, '--stack-file' => $stack,
            '--asset' => [base_path('distribution/install.sh'), base_path('distribution/shim.sh')], '--output' => $output,
        ])->assertSuccessful();

        expect(json_decode((string) file_get_contents($output), true)['assets']['compose.stack.yaml']['sha256'])->toBe(hash_file('sha256', $stack))
            ->and((string) file_get_contents($stack))->toContain('${FSA_REGISTRY:-ghcr.io}/beyerenrico/form-schema-app@'.$appDigest);
    } finally {
        unlink($inputs);
        unlink($output);
        @unlink($stack);
    }
});

it('prints the manifest to stdout without --output', function (): void {
    $registry = new FakeRegistry;
    [$inputs, $appDigest] = writeFakeReleaseInputs($registry);
    $registry->fake();

    try {
        $this->artisan('release:manifest', [
            'version' => '2.0.0', '--app-digest' => $appDigest, '--inputs' => $inputs, '--platform' => ['linux/arm64'],
            '--upgrades-from' => 'v2.0.0-rc.1', '--asset' => [base_path('distribution/install.sh'), base_path('distribution/shim.sh')],
        ])->expectsOutputToContain('"upgrades_from": "2.0.0-rc.1"')->assertSuccessful();
    } finally {
        unlink($inputs);
    }
});

it('fails without the app digest, with a missing pin or an unwritable output', function (): void {
    $registry = new FakeRegistry;
    [$inputs, $appDigest] = writeFakeReleaseInputs($registry);
    $registry->fake();

    try {
        $this->artisan('release:manifest', ['version' => '2.0.0', '--inputs' => $inputs])
            ->expectsOutputToContain('--app-digest is required')->assertFailed();
        $this->artisan('release:manifest', ['version' => '2.0.0', '--app-digest' => $appDigest, '--inputs' => $inputs.'.missing'])
            ->expectsOutputToContain('Cannot read the release inputs')->assertFailed();
        $this->artisan('release:manifest', ['version' => '2.0.0', '--app-digest' => $appDigest, '--inputs' => $inputs, '--output' => '/nonexistent/dir/manifest.json'])
            ->expectsOutputToContain('A release ships the asset "install.sh"')->assertFailed();
        $this->artisan('release:manifest', [
            'version' => '2.0.0', '--app-digest' => $appDigest, '--inputs' => $inputs, '--output' => '/nonexistent/dir/manifest.json',
            '--asset' => [base_path('distribution/install.sh'), base_path('distribution/shim.sh')],
        ])->expectsOutputToContain('Cannot write the manifest')->assertFailed();
    } finally {
        unlink($inputs);
    }
});
