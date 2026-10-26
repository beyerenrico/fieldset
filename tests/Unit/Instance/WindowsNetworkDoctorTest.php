<?php

declare(strict_types=1);

use App\Domain\Instance\Docker\WslBackend;
use App\Domain\Instance\Windows\WindowsFactsParser;
use Illuminate\Support\Facades\Artisan;
use Tests\Support\Instance\InstanceCommandFixture;
use Tests\Support\Instance\PhoneFixtures;
use Tests\Support\Instance\TemporaryInstance;

/*
 * 2.1.1 U14 (R12, R30, R32): the Windows network check inside `fieldset doctor` on a simulated WSL 2 host (the commands
 * in `details.commands` and, in readable output, under one header line), the certificate warning given once, and the
 * removal commands `fieldset uninstall` prints for the rules the doctor suggests.
 */

const WIN_DOCTOR_RULE = 'New-NetFirewallRule -Name fieldset-%s-https -DisplayName fieldset-%s-https -Direction Inbound -Action Allow -Protocol TCP -LocalPort 80,443 -Profile Private -RemoteAddress LocalSubnet';

beforeEach(function (): void {
    $this->fixture = new InstanceCommandFixture;
    $this->fixture->run('fieldset:install', InstanceCommandFixture::installFlags());
    $state = $this->fixture->state();
    $this->fixture->directory->saveState($state->withSettings(['phone_https' => 'local-ca', 'lan_address' => PhoneFixtures::LAN, 'edge_subnet' => PhoneFixtures::EDGE_SUBNET])->withUrl('https://'.PhoneFixtures::LAN));
    $this->id = substr($state->uuid(), 0, 8);
});

afterEach(fn () => TemporaryInstance::cleanup());

/** Simulated WSL 2 facts for the fixture: Windows' Wi-Fi address is PhoneFixtures::LAN, the distro's own 172.29.20.5. */
function windowsDoctorFacts(InstanceCommandFixture $fixture, string $rules, WslBackend $backend = WslBackend::DockerDesktop, string $address = PhoneFixtures::LAN, string $networking = 'nat'): void
{
    $document = "scope=full\nipv4={$address} Private Wi-Fi / Intel\nchecked=addresses\nnetworking={$networking}\n{$rules}checked=rules\nchecked=hyperv_rules\nchecked=portproxy\nchecked=listen\nend=1\n";
    $windows = WindowsFactsParser::parse($document);
    $fixture->facts(['wslVersion' => 2, 'wslBackend' => $backend, 'windows' => $windows, 'lanAddresses' => $windows->lanAddresses() ?? [], 'wslAddresses' => ['172.29.20.5']]);
}

it('fails doctor with the host firewall rule in details and under one header in readable output', function (): void {
    windowsDoctorFacts($this->fixture, '');

    [$code, $json] = $this->fixture->run('fieldset:doctor');
    $check = collect($json['data']['checks'])->keyBy('key')['windows_network'];
    $rule = sprintf(WIN_DOCTOR_RULE, $this->id, $this->id);
    Artisan::call('fieldset:doctor', ['--dir' => $this->fixture->directory->root]);
    $human = Artisan::output();

    expect($code)->toBe(3, json_encode($json))
        ->and($json['errors'][0]['code'])->toBe('doctor_failed')
        ->and($json['errors'][0]['message'])->toContain('windows_network: The Windows firewall has no inbound rule for TCP 80 and 443')
        ->and($check['status'])->toBe('fail')
        ->and($check['details']['commands'])->toBe([$rule])
        ->and($json['errors'][0]['hint'])->toBe("Run in PowerShell as administrator:\n    {$rule}\nThen run `fieldset doctor` again.")
        ->and($human)->toContain("  Fix: Run in PowerShell as administrator:\n    {$rule}\n");
});

it('passes doctor once the rule exists, and gives the changed-address warning once (windows_network, not lan_address)', function (): void {
    windowsDoctorFacts($this->fixture, "rule=80 2\nrule=443 2\n");
    [$code, $json] = $this->fixture->run('fieldset:doctor');
    windowsDoctorFacts($this->fixture, "rule=80 2\nrule=443 2\n", address: '192.168.1.33');
    [$movedCode, $moved] = $this->fixture->run('fieldset:doctor');
    $checks = collect($moved['data']['checks'])->keyBy('key');

    expect($code)->toBe(0, json_encode($json))
        ->and(collect($json['data']['checks'])->keyBy('key')['windows_network']['status'])->toBe('ok')
        ->and($movedCode)->toBe(0, json_encode($moved))
        ->and(collect($moved['warnings'])->pluck('code')->filter(fn (string $c): bool => $c === 'lan_address_changed')->count())->toBe(1)
        ->and($checks['lan_address']['status'])->toBe('skipped')
        ->and($checks['windows_network']['status'])->toBe('warn');
});

it('prints the removal commands on uninstall for the rules doctor suggests, per setup', function (WslBackend $backend, string $networking, array $expected): void {
    $this->fixture->withRunningStack();
    windowsDoctorFacts($this->fixture, '', $backend, networking: $networking);

    [$code, $json] = $this->fixture->run('fieldset:uninstall', ['--yes' => true]);
    $commands = array_map(fn (string $line): string => str_replace('<id>', $this->id, $line), $expected);
    $warning = collect($json['warnings'])->firstWhere('code', 'windows_rules');

    expect($code)->toBe(0, json_encode($json))
        ->and($json['data']['windows_cleanup'])->toBe($commands)
        ->and($warning['hint'])->toStartWith("Run in PowerShell as administrator:\n    ".$commands[0]);
})->with([
    'Docker Desktop' => [WslBackend::DockerDesktop, 'nat', ['Remove-NetFirewallRule -Name fieldset-<id>-https']],
    'Engine with NAT' => [WslBackend::Engine, 'nat', [
        'netsh interface portproxy delete v4tov4 listenaddress=192.168.1.20 listenport=80',
        'netsh interface portproxy delete v4tov4 listenaddress=192.168.1.20 listenport=443',
        'Remove-NetFirewallRule -Name fieldset-<id>-https',
    ]],
    'Engine with mirrored networking' => [WslBackend::Engine, 'mirrored', ['Remove-NetFirewallHyperVRule -Name fieldset-<id>-https-wsl']],
]);

it('prints no Windows removal commands outside WSL 2', function (): void {
    $this->fixture->withRunningStack();

    [$code, $json] = $this->fixture->run('fieldset:uninstall', ['--yes' => true]);

    expect($code)->toBe(0, json_encode($json))
        ->and($json['data'])->not->toHaveKey('windows_cleanup')
        ->and(collect($json['warnings'])->pluck('code'))->not->toContain('windows_rules');
});
