<?php

declare(strict_types=1);

use App\Domain\Instance\Docker\ProcessOutcome;
use Carbon\CarbonImmutable;
use Tests\Support\Instance\DemoFixtures;
use Tests\Support\Instance\InstanceCommandFixture;
use Tests\Support\Instance\PhoneFixtures;
use Tests\Support\Instance\TemporaryInstance;
use Tests\Support\Instance\TunnelFixture;

/*
 * M11 gate (A4–A6; ADR 0072, ADR 0082): `fieldset install --phone-https tunnel` reports what the tunnel did to the demo
 * passwords, resumes with the same flags (or none) and starts the tunnel then, and a repeated identical install after
 * the tunnel ended changes nothing and points to `fieldset tunnel start`.
 */

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-10-18T12:00:00Z');
    $this->fixture = new TunnelFixture;
    $this->fresh = new InstanceCommandFixture;
    $this->fixture->attach($this->fresh);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
    TemporaryInstance::cleanup();
});

it('reports the rotated demo passwords, not the install\'s default ones, when the tunnel then fails (A4)', function (): void {
    $this->fresh->oneOffs['app:demo-seed'] = DemoFixtures::seeded('default');
    $this->fixture->demoDefaults = DemoFixtures::EMAILS;
    $this->fixture->hostname = null;

    [$code, $json] = $this->fresh->run('fieldset:install', InstanceCommandFixture::installFlags(['--demo' => true, '--phone-https' => 'tunnel']));

    expect($code)->toBe(5, json_encode($json))
        ->and($json['command'])->toBe('install')
        ->and($json['data']['demo'])->toMatchArray(['passwords' => 'random', 'rotated' => true, 'delivered' => 'secrets'])
        ->and($json['data']['demo'])->not->toHaveKey('default_password')
        ->and(collect($json['secrets'])->pluck('name')->all())->toContain('demo_password:'.DemoFixtures::EMAILS[0])
        ->and(collect($json['warnings'])->pluck('code')->all())->toContain('demo_passwords_rotated');
});

it('resumes an interrupted install with --phone-https tunnel and starts the tunnel then (A5)', function (bool $sameFlags): void {
    $this->fresh->oneOffs['migrate'] = new ProcessOutcome(1, '', 'SQLSTATE[08006] connection refused');
    [$failed, $failedJson] = $this->fresh->run('fieldset:install', InstanceCommandFixture::installFlags(['--phone-https' => 'tunnel']));
    unset($this->fresh->oneOffs['migrate']);

    [$code, $json] = $this->fresh->run('fieldset:install', InstanceCommandFixture::installFlags($sameFlags ? ['--phone-https' => 'tunnel'] : []));

    expect($failed)->toBe(4, json_encode($failedJson))
        ->and($code)->toBe(0, json_encode($json))
        ->and($json['data']['url'])->toBe('https://'.PhoneFixtures::HOSTNAME)
        ->and($json['data']['tunnel']['state'] ?? null)->toBe('active')
        ->and($this->fresh->state()->setting('phone_https'))->toBe('tunnel')
        ->and($this->fixture->tunnelContainer())->toBe('running');
})->with(['the same flags' => true, 'without --phone-https' => false]);

it('changes nothing on a repeated identical install after the tunnel ended and points to `fieldset tunnel start` (A6)', function (): void {
    $flags = InstanceCommandFixture::installFlags(['--phone-https' => 'tunnel']);
    $this->fresh->run('fieldset:install', $flags);
    $this->fresh->run('fieldset:tunnel', ['action' => 'stop']);

    [$code, $json] = $this->fresh->run('fieldset:install', $flags);

    expect($code)->toBe(0, json_encode($json))
        ->and($json['changed'])->toBeFalse()
        ->and(collect($json['warnings'])->pluck('code')->all())->toContain('tunnel_not_running')
        ->and(collect($json['warnings'])->firstWhere('code', 'tunnel_not_running')['hint'])->toContain('`fieldset tunnel start`')
        ->and($this->fixture->tunnelContainer())->toBeNull();
});

it('still refuses --phone-https tunnel on a finished install that runs the local CA (A6)', function (): void {
    $this->fresh->run('fieldset:install', InstanceCommandFixture::installFlags());
    $directory = $this->fresh->directory;
    $directory->saveState($directory->state()->withSettings(['phone_https' => 'local-ca', 'lan_address' => PhoneFixtures::LAN, 'edge_subnet' => PhoneFixtures::EDGE_SUBNET]));

    [$code, $json] = $this->fresh->run('fieldset:install', InstanceCommandFixture::installFlags(['--phone-https' => 'tunnel']));

    expect($code)->toBe(2)
        ->and($json['errors'][0]['code'])->toBe('use_config_set')
        ->and($json['errors'][0]['hint'])->toContain('fieldset tunnel start');
});
