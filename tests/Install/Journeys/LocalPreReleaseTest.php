<?php

declare(strict_types=1);

use App\Domain\Instance\Release\ManifestSchema;
use App\Domain\Instance\Release\PlatformImage;
use App\Domain\Instance\Release\ReleaseManifest;
use Symfony\Component\Process\Process;
use Tests\Support\Docker\Cosign;
use Tests\Support\Docker\HarnessRelease;
use Tests\Support\Docker\InstanceJourney;
use Tests\Support\Docker\ReleaseHarness;
use Tests\Support\Docker\TestImage;

/*
 * The milestone pre-release, built and tested locally (BUILD_PROMPT_V2 §12, §15.6, E18; ADR 0064, ADR 0067). Runs
 * only with FIELDSET_PRERELEASE_VERSION (e.g. 2.0.0-alpha.1): the harness publishes exactly that version as a multi-arch
 * index (linux/amd64 + linux/arm64; the foreign platform's stages build under emulation), the manifest is
 * validated and its signature verified with the run's local key, then install.sh (harness build) installs it
 * natively, fieldset reports it, and fieldset uninstall removes it. The verified assets are kept in dist/v<version>/
 * (git-ignored; the images live only in the harness registry, which is removed afterwards), and a redacted
 * summary goes to docs/agent/milestones/evidence/<FIELDSET_EVIDENCE_MILESTONE, default M9>/prerelease-<version>.json. Nothing is pushed or published.
 */

beforeAll(function (): void {
    $version = getenv('FIELDSET_PRERELEASE_VERSION');
    if (! is_string($version) || $version === '' || TestImage::dockerUnavailableReason() !== null) {
        return;
    }

    try {
        $harness = ReleaseHarness::start();
        $GLOBALS['fieldsetPre'] = ['harness' => $harness];
        $started = microtime(true);
        $release = $harness->publish($version, PlatformImage::SUPPORTED);
        $GLOBALS['fieldsetPre'] += [
            'release' => $release,
            'journey' => new InstanceJourney($harness, $release, $harness->directory.'/prerelease'),
            'summary' => ['build_seconds' => round(microtime(true) - $started, 1)],
        ];
    } catch (Throwable $exception) {
        $GLOBALS['fieldsetPreError'] = $exception;
    }
});

afterAll(function (): void {
    if (isset($GLOBALS['fieldsetPre']['journey'])) {
        InstanceJourney::cleanup($GLOBALS['fieldsetPre']['journey']->work.'/fieldset-test-prerelease', $GLOBALS['fieldsetPre']['uuid'] ?? null);
    }
    if (isset($GLOBALS['fieldsetPre']['harness'])) {
        $GLOBALS['fieldsetPre']['harness']->stop();
    }
    unset($GLOBALS['fieldsetPre'], $GLOBALS['fieldsetPreError']);
});

beforeEach(function (): void {
    if (! is_string(getenv('FIELDSET_PRERELEASE_VERSION')) || getenv('FIELDSET_PRERELEASE_VERSION') === '') {
        $this->markTestSkipped('Set FIELDSET_PRERELEASE_VERSION (e.g. 2.0.0-alpha.1) to build and test the local pre-release.');
    }
    $reason = TestImage::dockerUnavailableReason();
    if ($reason !== null) {
        expect(getenv('FIELDSET_REQUIRE_DOCKER'))->not->toBe('1', $reason);
        $this->markTestSkipped($reason.' (install journeys need a Docker engine)');
    }
    if (isset($GLOBALS['fieldsetPreError'])) {
        throw $GLOBALS['fieldsetPreError'];
    }

    $this->harness = $GLOBALS['fieldsetPre']['harness'];
    $this->release = $GLOBALS['fieldsetPre']['release'];
    $this->journey = $GLOBALS['fieldsetPre']['journey'];
    $this->version = (string) $this->release->version;
    $this->dir = $this->journey->work.'/fieldset-test-prerelease';
});

/** The channel a pre-release version names (2.0.0-beta.1 → beta), read from the string, not from ReleaseVersion. */
function preReleaseChannel(string $version): string
{
    return preg_match('/^\d+\.\d+\.\d+-(alpha|beta|rc)\.\d+$/D', $version, $match) === 1 ? $match[1] : 'stable';
}

function preReleaseManifest(HarnessRelease $release): ReleaseManifest
{
    return ReleaseManifest::fromJson((string) file_get_contents($release->manifestPath()), new ManifestSchema(TestImage::repository().'/distribution/manifest.schema.json'));
}

/** @param  array<string, mixed>  $values */
function preReleaseRecord(array $values): void
{
    $GLOBALS['fieldsetPre']['summary'] = [...$GLOBALS['fieldsetPre']['summary'], ...$values];
}

it('publishes a signed multi-arch pre-release whose manifest validates and lists every image by digest', function (): void {
    $manifest = preReleaseManifest($this->release);
    $manifestCheck = $this->harness->cosign->verifyBlob($this->release->manifestPath(), $this->release->bundlePath());
    $scriptCheck = $this->harness->cosign->verifyBlob($this->release->directory.'/install.sh', $this->release->directory.'/install.sh.sigstore.json');
    $services = [];
    foreach ($manifest->images as $service => $image) {
        $services[$service] = ['image' => $image->pin->name().':'.$image->pin->tag, 'digest' => $image->pin->digest,
            'platforms' => array_map(static fn (PlatformImage $platform): array => ['digest' => $platform->digest, 'compressed_size' => $platform->compressedSize], $image->platforms)];
    }
    preReleaseRecord(['version' => $this->version, 'tag' => $this->release->version->tag(), 'channel' => $manifest->channel()->value,
        'platforms' => $manifest->platforms, 'app_index_digest' => $this->release->appDigest, 'images' => $services, 'assets' => $manifest->toArray()['assets'],
        'signatures' => ['key' => 'local harness key (cosign-harness.pub), not the keyless production identity', 'release-manifest.json' => $manifestCheck->isSuccessful(), 'install.sh' => $scriptCheck->isSuccessful()]]);

    expect((string) $manifest->version)->toBe($this->version)
        ->and($manifest->channel()->value)->toBe(preReleaseChannel($this->version))
        ->and($manifest->platforms)->toBe(PlatformImage::SUPPORTED)
        ->and(array_keys($manifest->images))->toBe(ReleaseManifest::SERVICES)
        ->and($manifest->images['app']->pin->name())->toBe('ghcr.io/beyerenrico/fieldset')
        ->and($manifest->images['app']->pin->tag)->toBe($this->version)
        ->and($manifest->images['app']->pin->digest)->toBe($this->release->appDigest)
        ->and($manifestCheck->getExitCode())->toBe(0, $manifestCheck->getErrorOutput())
        ->and($manifestCheck->getErrorOutput())->toContain('Verified OK')
        ->and($scriptCheck->getExitCode())->toBe(0, $scriptCheck->getErrorOutput());
    foreach ($manifest->images as $service => $image) {
        expect(array_keys($image->platforms))->toBe(PlatformImage::SUPPORTED, $service)
            ->and($image->pin->digest)->toMatch('/^sha256:[a-f0-9]{64}$/');
        foreach ($image->platforms as $platform) {
            expect($platform->digest)->toMatch('/^sha256:[a-f0-9]{64}$/')->and($platform->compressedSize)->toBeGreaterThan(0);
        }
    }
});

it('tags only the pre-release version and never moves latest, 2 or 2.0', function (): void {
    $command = new Process([PHP_BINARY, 'artisan', 'release:version', $this->release->version->tag()], TestImage::repository());
    $command->mustRun();
    $info = json_decode(trim($command->getOutput()), true);
    $tags = array_values(array_filter($this->harness->tags('beyerenrico/fieldset'), static fn (string $tag): bool => ! str_starts_with($tag, 'sha256-')));
    preReleaseRecord(['release_version' => $info, 'registry_tags' => $tags]);

    if (preReleaseChannel($this->version) === 'stable') {
        // The final release (§15.10) moves X.Y, X and latest and is also served from latest/download (KTD22).
        [$major, $minor] = explode('.', $this->version);
        $expected = [$this->version, "{$major}.{$minor}", $major, 'latest'];
        $sorted = static function (array $list): array {
            sort($list, SORT_STRING);

            return $list;
        };
        expect($info)->toMatchArray(['version' => $this->version, 'channel' => 'stable', 'prerelease' => false, 'image_tags' => $expected])
            ->and($sorted($tags))->toBe($sorted($expected))
            ->and(is_file($this->harness->latestDirectory().'/release-manifest.json'))->toBeTrue();

        return;
    }

    expect($info)->toMatchArray(['version' => $this->version, 'channel' => preReleaseChannel($this->version), 'prerelease' => true, 'image_tags' => [$this->version]])
        ->and(array_intersect($info['image_tags'], ['latest', '2', '2.0']))->toBe([])
        ->and($tags)->toBe([$this->version])
        ->and(is_dir($this->harness->latestDirectory()))->toBeFalse();
});

it('keeps the verified release assets and the local public key in dist/', function (): void {
    $target = TestImage::repository().'/dist/'.$this->release->version->tag();
    new Process(['rm', '-rf', $target])->mustRun();
    mkdir($target, 0755, true);
    foreach (glob($this->release->directory.'/*') ?: [] as $file) {
        copy($file, $target.'/'.basename($file));
    }
    copy($this->harness->cosign->publicKey(), $target.'/cosign-harness.pub');
    $sums = array_filter(explode("\n", (string) file_get_contents($target.'/SHA256SUMS')));
    $verified = array_map(static function (string $line) use ($target): bool {
        [$hash, $name] = explode('  ', $line, 2);

        return hash_file('sha256', $target.'/'.$name) === $hash;
    }, $sums);
    preReleaseRecord(['dist' => ['directory' => 'dist/'.$this->release->version->tag().'/ (git-ignored)', 'files' => array_values(array_diff(scandir($target) ?: [], ['.', '..']))]]);

    expect(array_values(array_diff(scandir($target) ?: [], ['.', '..'])))->toEqualCanonicalizing([
        'SHA256SUMS', 'cosign-harness.pub', 'install.sh', 'install.sh.sigstore.json', ReleaseHarness::MANIFEST, ReleaseHarness::MANIFEST.'.sigstore.json', 'shim.sh',
        ReleaseHarness::STACK_FILE,
    ])
        ->and($verified)->not->toBeEmpty()
        ->and(array_unique($verified))->toBe([true]);
});

it('installs it natively with the harness install.sh and reports it with fieldset status and fieldset version', function (): void {
    $secrets = $this->journey->work.'/owner-link.json';
    $started = microtime(true);
    $install = $this->journey->install($this->dir, ['--org', 'Pre-release Org', '--owner-email', 'owner@example.test', '--owner-name', 'Pat Owner', '--locale', 'en', '--secrets-file', $secrets, '--json']);
    $seconds = round(microtime(true) - $started, 1);
    $json = InstanceJourney::json($install);
    $GLOBALS['fieldsetPre']['uuid'] = InstanceJourney::uuid($this->dir);
    $GLOBALS['fieldsetPre']['port'] = $json['data']['port'] ?? null;
    $link = json_decode((string) @file_get_contents($secrets), true)['secrets'][0] ?? [];
    $GLOBALS['fieldsetPre']['link'] = $link['value'] ?? '';
    $status = $this->journey->fieldset($this->dir, ['status', '--json']);
    $version = $this->journey->fieldset($this->dir, ['version', '--json']);
    $statusJson = InstanceJourney::json($status);
    preReleaseRecord(['install' => ['command' => "install.sh --dir <work>/fieldset-test-prerelease --version {$this->version} --org \"Pre-release Org\" --owner-email owner@example.test --owner-name \"Pat Owner\" --locale en --secrets-file <work>/owner-link.json --json",
        'platform' => ReleaseHarness::nativePlatform(), 'emulated' => false, 'exit_code' => $install->getExitCode(), 'seconds' => $seconds, 'stdout' => $json],
        'status' => $statusJson, 'fieldset_version' => InstanceJourney::json($version)]);

    expect($install->getExitCode())->toBe(0, $install->getErrorOutput()."\n".$install->getOutput())
        ->and($this->release->platformEnvironment())->toBe([])
        ->and($json['data']['version'])->toBe($this->version)
        ->and($json['data']['sign_in_link']['delivered'])->toBe('secrets_file')
        ->and($install->getOutput().$install->getErrorOutput())->not->toContain('/login-link#')
        ->and($link['value'] ?? '')->toStartWith('http://localhost:'.$json['data']['port'].'/login-link#')
        ->and(substr(sprintf('%o', fileperms($secrets)), -3))->toBe('600')
        ->and($status->getExitCode())->toBe(0, $status->getErrorOutput().$status->getOutput())
        ->and($statusJson['data'])->toMatchArray(['installed' => true, 'version' => $this->version, 'health' => 'ok', 'mode' => 'local'])
        ->and(array_keys($statusJson['data']['services']))->toBe(['app', 'queue', 'scheduler', 'postgres', 'redis', 'gotenberg', 'mailpit', 'backup'])
        ->and($version->getExitCode())->toBe(0, $version->getOutput())
        ->and(InstanceJourney::json($version)['data'])->toMatchArray(['fieldset' => $this->version, 'instance' => $this->version]);
    foreach ($statusJson['data']['services'] as $service => $state) {
        expect($state['state'])->toBe('running', $service)->and($state['health'])->toBeIn(['healthy', null], $service);
    }
});

it('answers /up and /admin/login and lets the sign-in link from the secrets file lead into the password-set flow', function (): void {
    $http = InstanceJourney::http($GLOBALS['fieldsetPre']['port']);
    [, $token] = explode('#', $GLOBALS['fieldsetPre']['link'], 2) + [1 => ''];
    $up = $http->get('/up')->getStatusCode();
    $login = $http->get('/admin/login')->getStatusCode();
    $landing = $http->get('/login-link');
    preg_match('/name="_token" value="([^"]+)"/', (string) $landing->getBody(), $csrf);
    $consumed = $http->post('/login-link', ['form_params' => ['_token' => $csrf[1] ?? '', 'token' => $token]]);
    preReleaseRecord(['http' => ['/up' => $up, '/admin/login' => $login, '/login-link' => $landing->getStatusCode(), 'sign_in_link_consumed' => $consumed->getStatusCode(),
        'redirect_path' => parse_url($consumed->getHeaderLine('Location'), PHP_URL_PATH)]]);

    expect($up)->toBe(200)
        ->and($login)->toBe(200)
        ->and($landing->getStatusCode())->toBe(200)
        ->and($consumed->getStatusCode())->toBe(303)
        ->and($consumed->getHeaderLine('Location'))->toContain('/admin/password-reset/reset');
});

it('uninstalls with fieldset uninstall --yes without leaving a labelled container, volume or network', function (): void {
    $uuid = $GLOBALS['fieldsetPre']['uuid'];
    $before = InstanceJourney::labelled($uuid);
    $uninstall = $this->journey->fieldset($this->dir, ['uninstall', '--yes', '--json']);
    $after = InstanceJourney::labelled($uuid);
    $json = InstanceJourney::json($uninstall);
    preReleaseRecord(['uninstall' => ['exit_code' => $uninstall->getExitCode(), 'status' => $json['status'] ?? null, 'data_deleted' => $json['data']['data_deleted'] ?? null,
        'removed' => $json['data']['removed'] ?? null, 'labelled_before' => array_map(count(...), $before), 'labelled_after' => $after]]);

    expect($uninstall->getExitCode())->toBe(0, $uninstall->getErrorOutput().$uninstall->getOutput())
        ->and($before['containers'])->toHaveCount(8) // the backup service since U16 (ADR 0084)
        ->and($after)->toBe(['containers' => [], 'volumes' => [], 'networks' => []])
        ->and(array_values(array_diff(scandir($this->dir) ?: [], ['.', '..'])))->toBe([]);
});

it('writes a redacted summary for the milestone evidence', function (): void {
    $name = "prerelease-{$this->version}.json";
    $this->journey->evidence($name, ['release' => $this->version, 'built' => date('Y-m-d'), 'revision' => TestImage::revision(), 'published' => false,
        'note' => 'Built and tested against the local registry and release server (E18); the harness registry is removed after the run.',
        ...$GLOBALS['fieldsetPre']['summary']]);
    $json = (string) file_get_contents(TestImage::repository().'/docs/agent/milestones/evidence/'.InstanceJourney::evidenceMilestone().'/'.$name);
    $home = (string) getenv('HOME');

    expect(json_decode($json, true)['version'] ?? null)->toBe($this->version)
        ->and($json)->not->toContain('/login-link#')
        ->and($json)->not->toContain(TestImage::repository())
        ->and($home === '' || ! str_contains($json, $home))->toBeTrue()
        ->and($json)->not->toContain('BEGIN')
        ->and($json)->not->toContain(Cosign::PASSWORD);
});
