<?php

declare(strict_types=1);

use Tests\Support\Instance\ConfigFixture;
use Tests\Support\Instance\PhoneFixtures;
use Tests\Support\Instance\TemporaryInstance;
use Tests\Support\Instance\TunnelFixture;

/*
 * M11 gate (R29, AE2, E9; ADR 0081 amendment 2026-10-19): a `config set` change that makes the instance reachable in a
 * way it was not before (DemoPasswords::exposure() of the target has a reason the current state lacks) is refused in
 * its preflight, before anything changes, while an account still signs in with the demo password `password`: exit 3
 * `default_password_in_use`. Demo accounts are rotated first with `fieldset demo --rotate-passwords`; other accounts' owners
 * change theirs. A change that widens nothing does not run the check.
 */

beforeEach(fn () => $this->fixture = new TunnelFixture);

afterEach(fn () => TemporaryInstance::cleanup());

it('refuses config set domain on a local demo still on password, in the preflight, with nothing changed', function (array $demo, array $other, string $hint, array $users): void {
    $this->fixture->demoDefaults = $demo;
    $this->fixture->otherDefaults = $other;
    $before = $this->fixture->config->state()->toArray();
    $mark = $this->fixture->config->mark();

    [$code, $json] = $this->fixture->config->set('domain', ConfigFixture::DOMAIN, ['--yes' => true]);
    $journal = $this->fixture->config->archivedJournal();

    expect($code)->toBe(3, json_encode($json))
        ->and($json['errors'][0]['code'])->toBe('default_password_in_use')
        ->and($json['errors'][0]['message'])->toContain('domain mode')
        ->and($json['errors'][0]['hint'])->toContain($hint)
        ->and($json['data']['users'])->toBe($users)
        ->and($this->fixture->accountCalls)->toBe(['app:demo-passwords --check --json'])
        ->and($this->fixture->config->state()->toArray())->toBe($before)
        ->and($this->fixture->config->methodsSince($mark))->not->toContain('composeUp')
        ->and($this->fixture->config->methodsSince($mark))->not->toContain('composeStop')
        ->and(ConfigFixture::phases($journal))->toBe(['snapshot:completed', 'preflight:failed']);
})->with([
    'demo accounts' => [['owner@demo.test', 'worker@demo.test'], [], 'fieldset demo --rotate-passwords', ['owner@demo.test', 'worker@demo.test']],
    'another account' => [['owner@demo.test'], ['real@example.com'], 'Change the password of real@example.com', ['real@example.com']],
]);

it('switches to domain mode once no account signs in with password, checking the accounts once', function (): void {
    [$code, $json] = $this->fixture->config->set('domain', ConfigFixture::DOMAIN, ['--yes' => true]);

    expect($code)->toBe(0, json_encode($json))
        ->and($this->fixture->config->state()->mode())->toBe('domain')
        ->and($this->fixture->accountCalls)->toBe(['app:demo-passwords --check --json']);
});

it('does not check the accounts for a change that widens nothing', function (): void {
    $this->fixture->config->set('domain', ConfigFixture::DOMAIN, ['--yes' => true]);
    $this->fixture->accountCalls = [];
    $this->fixture->demoDefaults = ['owner@demo.test'];
    $this->fixture->config->instance->probes->dns['other.example.com'] = ['a' => [ConfigFixture::HOST_ADDRESS], 'aaaa' => []];

    [$renamed, $renamedJson] = $this->fixture->config->set('domain', 'other.example.com', ['--yes' => true]);
    [$local, $localJson] = $this->fixture->config->set('mode', 'local', ['--yes' => true]);
    [$port, $portJson] = $this->fixture->config->set('port', '8090', ['--yes' => true]);

    expect($renamed)->toBe(0, json_encode($renamedJson))
        ->and($local)->toBe(0, json_encode($localJson))
        ->and($port)->toBe(0, json_encode($portJson))
        ->and($this->fixture->accountCalls)->toBe([]);
});

it('checks the accounts once, not twice, when the local CA is turned on', function (): void {
    $this->fixture->config->instance->facts(['lanAddresses' => [PhoneFixtures::LAN]]);
    $this->fixture->demoDefaults = ['worker@demo.test'];

    [$code, $json] = $this->fixture->config->set('phone-https', 'local-ca', ['--yes' => true]);

    expect($code)->toBe(3, json_encode($json))
        ->and($json['errors'][0]['code'])->toBe('default_password_in_use')
        ->and($json['errors'][0]['message'])->toContain('the local CA')
        ->and($json['errors'][0]['hint'])->toContain('fieldset demo --rotate-passwords')
        ->and($this->fixture->accountCalls)->toBe(['app:demo-passwords --check --json']);
});
