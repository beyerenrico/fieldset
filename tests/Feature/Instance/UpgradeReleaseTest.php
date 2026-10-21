<?php

declare(strict_types=1);

use App\Domain\Instance\Docker\ContainerSpec;
use App\Domain\Instance\Docker\DockerCommandFailed;
use App\Domain\Instance\Docker\ProcessOutcome;
use App\Domain\Instance\Release\ManifestVerifier;
use Tests\Support\Instance\ConfigFixture;
use Tests\Support\Instance\TemporaryInstance;
use Tests\Support\Instance\UpgradeFixture;
use Tests\Support\Release\ReleaseFixtures;

/*
 * The target release of `fieldset upgrade` (R33, R58, KTD19 step 1, plan U18, ADR 0086): downloaded from the release URL into
 * a fresh directory, verified with the pinned cosign image before anything uses it, stored under `releases/<version>/`
 * only then; every way it can fail ends before anything changes (exit 2, 3), and the child's launch failing rolls back.
 */

beforeEach(function (): void {
    $this->upgrade = new UpgradeFixture;
    $this->dir = $this->upgrade->instance->directory->root;
    $this->config = $this->upgrade->restore->config;
});

afterEach(function (): void {
    TemporaryInstance::cleanup();
});

it('stores a verified target release under releases/<version>/ and leaves no download directory behind', function (): void {
    [$code] = $this->upgrade->upgrade();

    expect($code)->toBe(0)
        ->and(is_file($this->dir.'/releases/'.UpgradeFixture::TARGET.'/'.ManifestVerifier::MANIFEST))->toBeTrue()
        ->and(is_file($this->dir.'/releases/'.UpgradeFixture::TARGET.'/'.ManifestVerifier::BUNDLE))->toBeTrue()
        ->and(glob($this->dir.'/releases/.download-*', GLOB_ONLYDIR))->toBe([]);
});

it('refuses an unusable --to or release URL, an unknown release and an unreachable server before anything changes', function (array $options, ?string $url, ?int $status, int $exit, string $error): void {
    if ($url !== null) {
        $this->upgrade->instance->facts(['lanAddresses' => [ConfigFixture::HOST_ADDRESS], 'releaseUrl' => $url]);
    }
    $this->upgrade->releaseStatus = $status;
    $mark = $this->config->mark();

    [$code, $json] = $this->upgrade->upgrade($options);

    expect($code)->toBe($exit, (string) json_encode($json))->and($json['errors'][0]['code'])->toBe($error)
        ->and($this->config->callsSince($mark, 'composeStop'))->toBe([])
        ->and(is_dir($this->dir.'/releases/9.9.9'))->toBeFalse();
})->with([
    'not a version' => [['--to' => 'latest-and-greatest'], null, null, 2, 'invalid_version'],
    'plain http outside the harness' => [[], 'http://releases.test/fieldset', null, 2, 'insecure_release_url'],
    'no such release' => [['--to' => '9.9.9'], null, null, 2, 'release_not_found'],
    'server error' => [[], null, 500, 3, 'release_unavailable'],
]);

it('refuses a manifest of another version than --to, and one whose signature does not verify (exit 3)', function (string $case, string $error): void {
    if ($case === 'mismatch') {
        $this->upgrade->releases[UpgradeFixture::TARGET] = ReleaseFixtures::manifest('2.0.0-alpha.3', UpgradeFixture::TARGET_DIGEST)->toJson();
    } else {
        $docker = $this->upgrade->instance->docker;
        $previous = $docker->runResponder;
        $docker->respondToRun(fn (ContainerSpec $spec): ?ProcessOutcome => $spec->image === ManifestVerifier::COSIGN_IMAGE ? new ProcessOutcome(1, '', 'Error: none of the expected identities matched') : $previous($spec));
    }

    [$code, $json] = $this->upgrade->upgrade();

    expect($code)->toBe(3, (string) json_encode($json))->and($json['errors'][0]['code'])->toBe($error)
        ->and(is_dir($this->dir.'/releases/'.UpgradeFixture::TARGET))->toBeFalse()
        ->and($this->upgrade->gate()['raised'])->toBeFalse();
})->with([['mismatch', 'release_mismatch'], ['signature', 'manifest_signature_invalid']]);

it('rolls back when the target image\'s child does not even start', function (): void {
    $docker = $this->upgrade->instance->docker;
    $previous = $docker->runResponder;
    $docker->respondToRun(fn (ContainerSpec $spec): ?ProcessOutcome => ($spec->command[0] ?? null) === 'upgrade-child' ? new ProcessOutcome(125, '', 'docker: Error response from daemon: pull access denied') : $previous($spec));

    [$code, $json] = $this->upgrade->upgrade();

    expect($code)->toBe(5, (string) json_encode($json))->and($json['errors'][0]['code'])->toBe('child_failed')
        ->and($this->upgrade->instance->state()->version())->toBe('2.0.0-alpha.1');
});

it('reports a failed pull before anything changes (exit 9)', function (): void {
    $this->upgrade->instance->docker->onCall['pullImage'] = static function (array $args): void {
        throw new DockerCommandFailed('pull', new ProcessOutcome(1, '', 'manifest unknown'));
    };
    $mark = $this->config->mark();

    [$code, $json] = $this->upgrade->upgrade();

    expect($code)->toBe(9, (string) json_encode($json))->and($json['errors'][0]['code'])->toBe('pull_failed')
        ->and($this->config->callsSince($mark, 'composeStop'))->toBe([]);
});
