<?php

declare(strict_types=1);

use Tests\Support\Instance\TemporaryInstance;
use Tests\Support\Instance\UpgradeFixture;

/*
 * The staged files a child leaves (plan U18, KTD19 steps 6–7, KTD25, ADR 0086, review U18 S2): only the compose file,
 * the env files of its services and the known service files are taken, env files always 0600; anything else in the stage
 * is refused, as is a staged compose file that reaches past the containers or runs an image the release does not pin.
 * The previous image's stage of `fsa rollback` passes the same validation before any of its files is used.
 */

beforeEach(function (): void {
    $this->upgrade = new UpgradeFixture;
    $this->dir = $this->upgrade->instance->directory->root;
});

afterEach(function (): void {
    TemporaryInstance::cleanup();
});

it('refuses a stage holding files outside the allowlist, and copies none of them', function (string $file): void {
    $state = (string) file_get_contents($this->dir.'/state.json');
    $this->upgrade->editStagedCompose(static fn (array $document): array => $document, static function (string $root) use ($file): void {
        @mkdir(dirname($root.'/'.$file), 0700, true);
        file_put_contents($root.'/'.$file, '{"planted":true}');
    });

    [$code, $json] = $this->upgrade->upgrade();

    expect($code)->toBe(5, (string) json_encode($json))
        ->and($json['errors'][0]['code'])->toBe('staged_invalid')
        ->and($json['errors'][0]['message'])->toContain($file)
        ->and(is_file($this->dir.'/'.$file) && str_contains((string) file_get_contents($this->dir.'/'.$file), 'planted'))->toBeFalse()
        ->and((string) file_get_contents($this->dir.'/state.json'))->not->toContain('planted')
        ->and($this->upgrade->instance->state()->version())->toBe(json_decode($state, true)['version']);
})->with(['state.json', 'operations/current.json', 'locks/instance.lock', 'releases/9.9.9/release-manifest.json', 'backups/manual-20261020T100000Z/manifest.json', 'notes.txt', 'env/ghost.env']);

it('installs env files 0600 whatever mode the child gave them', function (): void {
    $this->upgrade->editStagedCompose(static fn (array $document): array => $document, static function (string $root): void {
        chmod($root.'/env/app.env', 0644);
    });

    [$code, $json] = $this->upgrade->upgrade();

    expect($code)->toBe(0, (string) json_encode($json))
        ->and(fileperms($this->dir.'/env/app.env') & 0777)->toBe(0600);
});

it('refuses a staged compose file that reaches past the containers or runs an image the release does not pin', function (Closure $edit): void {
    $this->upgrade->editStagedCompose($edit);

    [$code, $json] = $this->upgrade->upgrade();

    expect($code)->toBe(5, (string) json_encode($json))
        ->and($json['errors'][0]['code'])->toBe('staged_invalid')
        ->and($this->upgrade->compose())->not->toContain(UpgradeFixture::TARGET_DIGEST);
})->with([
    'an image the release does not pin' => [static fn (array $d): array => [...$d, 'services' => [...$d['services'], 'gotenberg' => [...$d['services']['gotenberg'], 'image' => 'docker.io/library/alpine:latest']]]],
    'a host bind' => [static fn (array $d): array => [...$d, 'services' => [...$d['services'], 'gotenberg' => [...$d['services']['gotenberg'], 'volumes' => [['type' => 'bind', 'source' => '/', 'target' => '/host']]]]]],
    'a capability' => [static fn (array $d): array => [...$d, 'services' => [...$d['services'], 'gotenberg' => [...$d['services']['gotenberg'], 'cap_add' => ['SYS_ADMIN']]]]],
    'the host network' => [static fn (array $d): array => [...$d, 'services' => [...$d['services'], 'gotenberg' => [...$d['services']['gotenberg'], 'network_mode' => 'host']]]],
    'an env file elsewhere' => [static fn (array $d): array => [...$d, 'services' => [...$d['services'], 'gotenberg' => [...$d['services']['gotenberg'], 'env_file' => ['/etc/shadow']]]]],
    'an included compose file' => [static fn (array $d): array => [...$d, 'include' => ['/srv/other/compose.yaml']]],
]);

it('validates the previous image\'s stage of fsa rollback before using any of its files', function (): void {
    [$code] = $this->upgrade->upgrade();
    expect($code)->toBe(0);
    $this->upgrade->editStagedCompose(static fn (array $d): array => [...$d, 'services' => [...$d['services'], 'gotenberg' => [...$d['services']['gotenberg'], 'volumes' => [['type' => 'bind', 'source' => '/var/run/docker.sock', 'target' => '/var/run/docker.sock']]]]]);

    [$code, $json] = $this->upgrade->rollback();

    expect($code)->toBe(5, (string) json_encode($json))
        ->and($json['errors'][0]['code'])->toBe('staged_invalid')
        ->and($this->upgrade->instance->oneOffCalls('instance:restore-finish'))->toBe([])
        ->and($this->upgrade->instance->state()->version())->toBe(UpgradeFixture::TARGET)
        ->and($this->upgrade->compose())->not->toContain('docker.sock');
});
