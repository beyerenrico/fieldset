<?php

declare(strict_types=1);

use Tests\Support\Instance\ConfigFixture;
use Tests\Support\Instance\TemporaryInstance;

/*
 * `fieldset config set update-check on|off` (E15, R32, R48, plan U20, ADR 0061, ADR 0089): off by default. `on` records the
 * newest release's manifest URL under the release URL in force (FIELDSET_RELEASE_URL or GitHub Releases) in runtime.json, which
 * the app's scheduler reads; no service is recreated, and `config show` names the contact. `off` removes it again.
 */

const CONFIG_UPDATE_GITHUB = 'https://github.com/beyerenrico/form-schema-app/releases/latest/download/release-manifest.json';

beforeEach(function (): void {
    $this->config = new ConfigFixture;
});

afterEach(function (): void {
    TemporaryInstance::cleanup();
});

/** @return array<string, mixed>|null */
function runtimeUpdates(ConfigFixture $config): ?array
{
    $runtime = json_decode((string) $config->instance->directory->read('runtime/runtime.json'), true);

    return is_array($runtime) ? ($runtime['updates'] ?? null) : null;
}

it('is off by default and contacts nothing', function (): void {
    [, $show] = $this->config->show();

    expect(runtimeUpdates($this->config))->toBe(['check' => false, 'manifest_url' => null])
        ->and(collect($show['data']['keys'])->firstWhere('key', 'update-check'))->toMatchArray(['value' => 'off', 'available' => true, 'settable_now' => true])
        ->and(collect($show['data']['outbound_contacts']['features'])->firstWhere('feature', 'update_check'))->toMatchArray(['active' => false, 'contacts' => []]);
});

it('turns the check on and off through runtime.json without recreating a service', function (): void {
    [$on, $onJson] = $this->config->set('update-check', 'on');
    $afterOn = runtimeUpdates($this->config);
    [, $show] = $this->config->show();
    [$again, $againJson] = $this->config->set('update-check', 'on');
    [$off, $offJson] = $this->config->set('update-check', 'off');

    expect($on)->toBe(0, (string) json_encode($onJson))
        ->and($onJson['data']['services']['recreated'] ?? null)->toBe([])
        ->and($afterOn)->toBe(['check' => true, 'manifest_url' => CONFIG_UPDATE_GITHUB])
        ->and(collect($show['data']['keys'])->firstWhere('key', 'update-check')['value'] ?? null)->toBe('on')
        ->and(collect($show['data']['outbound_contacts']['features'])->firstWhere('feature', 'update_check'))->toMatchArray(['active' => true, 'contacts' => [CONFIG_UPDATE_GITHUB]])
        ->and($again)->toBe(0)->and($againJson['changed'] ?? null)->toBeFalse()
        ->and($off)->toBe(0, (string) json_encode($offJson))
        ->and(runtimeUpdates($this->config))->toBe(['check' => false, 'manifest_url' => null])
        ->and($this->config->state()->setting('update_check'))->toBeNull()
        ->and($this->config->state()->setting('update_url'))->toBeNull();
});

it('reads the release mirror named by FIELDSET_RELEASE_URL', function (): void {
    $this->config->instance->facts(['releaseUrl' => 'https://mirror.example.com/fieldset/releases/']);

    [$code, $json] = $this->config->set('update-check', 'on');

    expect($code)->toBe(0, (string) json_encode($json))
        ->and(runtimeUpdates($this->config)['manifest_url'] ?? null)->toBe('https://mirror.example.com/fieldset/releases/latest/download/release-manifest.json');
});

it('refuses other values and an http release URL outside harness builds with exit 2', function (string $value, ?string $releaseUrl, string $error): void {
    if ($releaseUrl !== null) {
        $this->config->instance->facts(['releaseUrl' => $releaseUrl]);
    }

    [$code, $json] = $this->config->set('update-check', $value);

    expect($code)->toBe(2)->and($json['errors'][0]['code'])->toBe($error)
        ->and(runtimeUpdates($this->config))->toBe(['check' => false, 'manifest_url' => null]);
})->with([
    'yes' => ['yes', null, 'invalid_value'],
    'empty' => [' ', null, 'invalid_usage'],
    'http mirror' => ['on', 'http://mirror.example.com/releases', 'insecure_release_url'],
]);
