<?php

declare(strict_types=1);

use App\Domain\Instance\Docker\ContainerSpec;
use App\Domain\Instance\Docker\Labels;
use App\Domain\Instance\Docker\ProcessOutcome;
use App\Domain\Instance\Doctor\CheckResult;
use App\Domain\Instance\Doctor\Checks\LanAddressCheck;
use App\Domain\Instance\Doctor\Checks\LocalCaPorts;
use App\Domain\Instance\Doctor\CheckStatus;
use App\Domain\Instance\Doctor\DoctorContext;
use App\Domain\Instance\Doctor\PortProbe;
use App\Domain\Instance\HostFacts;
use App\Domain\Instance\State\InstanceState;
use Tests\Support\Instance\InstanceCommandFixture;
use Tests\Support\Instance\PhoneFixtures;
use Tests\Support\Instance\TemporaryInstance;

/*
 * Plan U14 (R19, ADR 0082): the doctor's local-CA parts — 80/443 on the LAN address (LocalCaPorts) and whether the
 * certificate's address is still the host's (LanAddressCheck).
 */

beforeEach(function (): void {
    $this->fixture = new InstanceCommandFixture;
    $this->fixture->run('fieldset:install', InstanceCommandFixture::installFlags());
    $this->state = $this->fixture->state()->withSettings(['phone_https' => 'local-ca', 'lan_address' => PhoneFixtures::LAN, 'edge_subnet' => PhoneFixtures::EDGE_SUBNET])->withUrl('https://'.PhoneFixtures::LAN);
});

afterEach(fn () => TemporaryInstance::cleanup());

function localCaContext(object $test, ?InstanceState $state = null, array $lanAddresses = [PhoneFixtures::LAN]): DoctorContext
{
    return new DoctorContext(new HostFacts(lanAddresses: $lanAddresses), $test->fixture->directory, $test->fixture->docker, $test->fixture->probes, $state ?? $test->state, null, InstanceCommandFixture::appImage(), uuid: $test->state->uuid(), lanAddress: PhoneFixtures::LAN);
}

it('counts 80 and 443 as served when this instance\'s edge runs, without probing them', function (): void {
    $this->fixture->docker->withRunningContainer('ed9e', '2026-10-18T12:00:00Z', [Labels::COMPOSE_PROJECT => $this->state->project(), Labels::COMPOSE_SERVICE => 'edge']);
    $probes = count($this->fixture->runSpecs('true'));

    $result = new LocalCaPorts(app(PortProbe::class))->check(localCaContext($this), InstanceCommandFixture::appImage(), CheckResult::ok('ports', 'Loopback fine.'));

    expect($result->status)->toBe(CheckStatus::Ok)
        ->and($result->summary)->toContain('served by this instance\'s edge')
        ->and($result->details['lan']['served'])->toBeTrue()
        ->and(count($this->fixture->runSpecs('true')))->toBe($probes);
});

it('reports rootless Docker that may not publish 80 on the LAN address with the sysctl, and keeps a loopback failure', function (): void {
    $this->fixture->docker->respondToRun(static fn (ContainerSpec $spec): ?ProcessOutcome => $spec->entrypoint === 'true'
        ? new ProcessOutcome(125, '', 'rootlesskit: cannot expose privileged port 80')
        : null);
    $check = new LocalCaPorts(app(PortProbe::class));

    $rootless = $check->check(localCaContext($this), InstanceCommandFixture::appImage(), CheckResult::ok('ports', 'Loopback fine.'));
    $loopback = $check->check(localCaContext($this), InstanceCommandFixture::appImage(), CheckResult::fail('ports', 'Port 8080 is in use.', 'Free it.'));

    expect($rootless->status)->toBe(CheckStatus::Fail)
        ->and($rootless->hint)->toBe(PortProbe::ROOTLESS_HINT)
        ->and($loopback->status)->toBe(CheckStatus::Fail)
        ->and($loopback->summary)->toBe('Port 8080 is in use.')
        ->and($loopback->details['lan']['address'])->toBe(PhoneFixtures::LAN);
});

it('skips the LAN address check without the local CA or without addresses to compare', function (): void {
    $check = new LanAddressCheck;

    expect($check->run(localCaContext($this, $this->fixture->state()))->status)->toBe(CheckStatus::Skipped)
        ->and($check->run(localCaContext($this, null, []))->status)->toBe(CheckStatus::Skipped)
        ->and($check->run(localCaContext($this))->status)->toBe(CheckStatus::Ok)
        ->and($check->run(localCaContext($this, null, ['10.9.9.9']))->hint)->toContain('fieldset config set lan-address 10.9.9.9 --yes')
        ->and(LanAddressCheck::hint(null))->toContain('<address>');
});
