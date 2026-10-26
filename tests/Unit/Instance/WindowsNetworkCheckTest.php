<?php

declare(strict_types=1);

use App\Domain\Instance\Doctor\CheckResult;
use App\Domain\Instance\Doctor\Checks\LanAddressCheck;
use App\Domain\Instance\Doctor\Checks\WindowsNetworkCheck;
use App\Domain\Instance\Doctor\CheckStatus;
use App\Domain\Instance\Doctor\DoctorContext;
use App\Domain\Instance\HostFacts;
use App\Domain\Instance\LocalCa\EdgeBinding;
use App\Domain\Instance\Windows\WindowsCommands;
use Tests\Support\Instance\InstanceCommandFixture;
use Tests\Support\Instance\TemporaryInstance;

/*
 * 2.1.1 U14 (R12, R30, KTD9, KTD10): on WSL 2 with phone HTTPS through the local CA, the doctor says whether Windows lets
 * phones reach the edge, with exactly one command set: the first matching row of the hint table. Every printed line is
 * built from validated values and the instance's rule names; fieldset never runs them.
 */

const NET_UUID = '3f1c2b9e-8a4d-4c1e-9b7a-2d5e6f708192';

const NET_WIFI = '192.168.1.20';

const NET_WSL = '172.29.20.5';

const NET_HOST_RULE = 'New-NetFirewallRule -Name fieldset-3f1c2b9e-https -DisplayName fieldset-3f1c2b9e-https -Direction Inbound -Action Allow -Protocol TCP -LocalPort 80,443 -Profile Private -RemoteAddress LocalSubnet';

const NET_HYPERV_RULE = "New-NetFirewallHyperVRule -Name fieldset-3f1c2b9e-https-wsl -DisplayName fieldset-3f1c2b9e-https-wsl -Direction Inbound -VMCreatorId '{40E0AC32-46A5-438A-A0B2-2B479E8F2E90}' -Protocol TCP -LocalPorts 80,443";

beforeEach(function (): void {
    $this->fixture = new InstanceCommandFixture;
});

afterEach(fn () => TemporaryInstance::cleanup());

/**
 * A full Windows facts document of a healthy Docker Desktop setup; `$replace` swaps lines by key (null drops them).
 *
 * @param  array<string, list<string>|null>  $replace
 */
function networkDocument(array $replace = [], string $scope = 'full'): string
{
    $lines = [
        'ipv4' => [NET_WIFI.' Private Wi-Fi / Intel(R) Wi-Fi 6 AX201', '172.24.0.1 Public vEthernet (WSL) / Hyper-V Virtual Ethernet Adapter'],
        'networking' => ['nat'],
        'binding' => ['default-port-binding'],
        'rule' => ['80 2', '443 2'],
        'hyperv_enabled' => ['True'],
        'hyperv_inbound' => ['Block'],
        'hyperv_rule' => [],
        'portproxy' => [],
        'listen' => [],
        'checked' => ['addresses', 'rules', 'hyperv_rules', 'portproxy', 'listen'],
        ...$replace,
    ];
    $text = "scope={$scope}\n";
    foreach (array_filter($lines, is_array(...)) as $key => $values) {
        foreach ($values as $value) {
            $text .= "{$key}={$value}\n";
        }
    }

    return $text."end=1\n";
}

/** What a 2.1.1 shim on WSL 2 hands over for doctor: the Windows document, the engine, the distro's own addresses. */
function networkFacts(?string $document, string $backend = 'desktop', string $wsl = '2'): HostFacts
{
    return HostFacts::fromEnvironment(array_filter([
        'FIELDSET_WSL' => $wsl, 'FIELDSET_WSL_BACKEND' => $backend, 'FIELDSET_WSL_ADDRESSES' => NET_WSL.' 172.17.0.1',
        'FIELDSET_WINDOWS_FACTS' => $document, 'FIELDSET_LAN_ADDRESSES' => '',
    ], is_string(...)));
}

function networkCheck(object $test, HostFacts $facts, ?string $address = NET_WIFI, bool $installing = false): CheckResult
{
    $binding = $address === null ? null : EdgeBinding::decide($address, $facts);
    $context = new DoctorContext($facts, $test->fixture->directory, $test->fixture->docker, $test->fixture->probes,
        selectPorts: $installing, uuid: NET_UUID, lanAddress: $address, edgeBinding: $binding);

    return new WindowsNetworkCheck()->run($context);
}

it('passes on Docker Desktop with a Private network and a firewall rule for 80 and 443', function (): void {
    $result = networkCheck($this, networkFacts(networkDocument()));

    expect($result->status)->toBe(CheckStatus::Ok, $result->summary)
        ->and($result->hint)->toBeNull()
        ->and($result->details)->toMatchArray(['address' => NET_WIFI, 'setup' => 'desktop', 'facts' => 'full', 'row' => null, 'commands' => [], 'unknown' => []]);
});

it('fails on Docker Desktop without a matching rule, with the host firewall rule scoped to the local subnet (AE4)', function (): void {
    $result = networkCheck($this, networkFacts(networkDocument(['rule' => ['443 4']])));

    expect($result->status)->toBe(CheckStatus::Fail)
        ->and($result->summary)->toContain('no inbound rule for TCP 80 and 443 on the Private profile')
        ->and($result->details['row'])->toBe('host_rule')
        ->and($result->details['commands'])->toBe([NET_HOST_RULE])
        ->and($result->hint)->toBe("Run in PowerShell as administrator:\n    ".NET_HOST_RULE."\nThen run `fieldset doctor` again.");
});

it('prints only the profile hint for a Public network, even when the rule is missing too', function (): void {
    $result = networkCheck($this, networkFacts(networkDocument(['ipv4' => [NET_WIFI.' Public Wi-Fi / Intel'], 'rule' => []])));

    expect($result->status)->toBe(CheckStatus::Fail)
        ->and($result->details['row'])->toBe('public_profile')
        ->and($result->details['commands'])->toBe(['Set-NetConnectionProfile -InterfaceIndex (Get-NetIPAddress -IPAddress 192.168.1.20).InterfaceIndex -NetworkCategory Private'])
        ->and($result->hint)->toContain('Private')->toContain(WindowsCommands::HEADER)->not->toContain('New-NetFirewallRule');
});

it('prints only the Docker Desktop setting for local-only port binding, even when the rule is missing too', function (): void {
    $result = networkCheck($this, networkFacts(networkDocument(['binding' => ['local-only-port-binding'], 'rule' => []])));

    expect($result->status)->toBe(CheckStatus::Fail)
        ->and($result->details['row'])->toBe('desktop_local_only')
        ->and($result->details['commands'])->toBe([])
        ->and($result->hint)->toContain('port binding setting')->not->toContain('New-NetFirewallRule')->not->toContain(WindowsCommands::HEADER);
});

it('reports unknown with the manual steps and the lan-address setting when interop was unavailable', function (string $document, array $unknown): void {
    $result = networkCheck($this, networkFacts($document));

    expect($result->status)->toBe(CheckStatus::Unknown)
        ->and($result->code)->toBe(WindowsNetworkCheck::UNKNOWN)
        ->and($result->details['row'])->toBe('facts_unknown')
        ->and($result->details['unknown'])->toBe($unknown)
        ->and($result->hint)->toContain('Private')->toContain('inbound TCP 80 and 443')->toContain('`fieldset config set lan-address <address> --yes`')
        ->and($result->details['commands'])->toBe([NET_HOST_RULE]);
})->with([
    'interop off' => ["scope=unavailable\n", ['network profile', 'firewall rules', 'listeners']],
    'firewall section failed' => [networkDocument(['checked' => ['addresses', 'hyperv_rules', 'portproxy', 'listen']]), ['firewall rules']],
    'listeners unknown' => [networkDocument(['checked' => ['addresses', 'rules', 'hyperv_rules', 'portproxy']]), ['listeners']],
]);

it('reports unknown without commands when Engine\'s networking mode is unknown', function (): void {
    $result = networkCheck($this, networkFacts("scope=unavailable\n", 'engine'));

    expect($result->status)->toBe(CheckStatus::Unknown)
        ->and($result->details['setup'])->toBe('unknown')
        ->and($result->details['unknown'])->toContain('networking mode')
        ->and($result->details['commands'])->toBe([])
        ->and($result->hint)->toContain('portproxy')->toContain('Hyper-V firewall rule');
});

it('passes on Engine with NAT when a portproxy forwards both ports to the distro and the rule exists', function (): void {
    $document = networkDocument(['portproxy' => [NET_WIFI.' 80 '.NET_WSL.' 80', '* 443 '.NET_WSL.' 443']]);

    expect(networkCheck($this, networkFacts($document, 'engine'))->status)->toBe(CheckStatus::Ok);
});

it('deletes a portproxy that points at yesterday\'s WSL address and adds the current one, plus the missing rule', function (): void {
    $document = networkDocument(['portproxy' => [NET_WIFI.' 443 172.29.16.9 443', '* 80 wsl.local 80'], 'rule' => ['443 2']]);

    $result = networkCheck($this, networkFacts($document, 'engine'));

    expect($result->status)->toBe(CheckStatus::Fail)
        ->and($result->details['setup'])->toBe('engine-nat')
        ->and($result->details['row'])->toBe('portproxy')
        ->and($result->summary)->toContain('points at 172.29.16.9:443, not at WSL\'s 172.29.20.5:443')->toContain('points at a host name:80')
        ->and($result->details['commands'])->toBe([
            'netsh interface portproxy delete v4tov4 listenport=80',
            'netsh interface portproxy delete v4tov4 listenaddress=192.168.1.20 listenport=443',
            'netsh interface portproxy add v4tov4 listenaddress=192.168.1.20 listenport=80 connectaddress=172.29.20.5 connectport=80',
            'netsh interface portproxy add v4tov4 listenaddress=192.168.1.20 listenport=443 connectaddress=172.29.20.5 connectport=443',
            NET_HOST_RULE,
        ]);
});

it('adds the portproxy alone when nothing forwards yet and the rule exists', function (): void {
    $result = networkCheck($this, networkFacts(networkDocument(), 'engine'));

    expect($result->status)->toBe(CheckStatus::Fail)
        ->and($result->summary)->toContain('no portproxy forwards port 80 from 192.168.1.20 to WSL')
        ->and($result->details['commands'])->toBe([
            'netsh interface portproxy add v4tov4 listenaddress=192.168.1.20 listenport=80 connectaddress=172.29.20.5 connectport=80',
            'netsh interface portproxy add v4tov4 listenaddress=192.168.1.20 listenport=443 connectaddress=172.29.20.5 connectport=443',
        ]);
});

it('prints the Hyper-V rule for Engine with mirrored networking, and passes once it exists or the firewall lets traffic in', function (): void {
    $missing = networkCheck($this, networkFacts(networkDocument(['networking' => ['mirrored'], 'rule' => []]), 'engine'));
    $present = networkCheck($this, networkFacts(networkDocument(['networking' => ['mirrored'], 'hyperv_rule' => ['80', '443']]), 'engine'));
    $open = networkCheck($this, networkFacts(networkDocument(['networking' => ['mirrored'], 'hyperv_inbound' => ['Allow']]), 'engine'));

    expect($missing->status)->toBe(CheckStatus::Fail)
        ->and($missing->details)->toMatchArray(['setup' => 'engine-mirrored', 'row' => 'hyperv_rule', 'commands' => [NET_HYPERV_RULE]])
        ->and($missing->hint)->not->toContain('New-NetFirewallRule ')
        ->and($present->status)->toBe(CheckStatus::Ok)
        ->and($open->status)->toBe(CheckStatus::Ok);
});

it('warns with the re-issue offer when Windows\' address changed since the certificate was issued', function (): void {
    $result = networkCheck($this, networkFacts(networkDocument(['ipv4' => ['192.168.1.33 Private Wi-Fi / Intel']])));

    expect($result->status)->toBe(CheckStatus::Warn)
        ->and($result->code)->toBe(LanAddressCheck::WARNING)
        ->and($result->summary)->toContain('192.168.1.20, which is not one of Windows\' LAN addresses now')
        ->and($result->hint)->toContain('fieldset config set lan-address 192.168.1.33 --yes')
        ->and($result->details['candidates'])->toBe(['192.168.1.33']);
});

it('fails with a clear port message when IIS listens on 443 on Windows, before start too', function (bool $installing): void {
    $document = networkDocument(['listen' => ['443 0.0.0.0 4 System', '80 127.0.0.1 7000 nginx', '80 0.0.0.0 5120 com.docker.backend']]);

    $result = networkCheck($this, networkFacts($document), installing: $installing);

    expect($result->status)->toBe(CheckStatus::Fail)
        ->and($result->summary)->toBe('Port 443 is in use on Windows by the Windows HTTP server (PID 4: IIS or another HTTP.sys service), so phones cannot reach the edge at https://192.168.1.20.')
        ->and($result->hint)->toContain('Stop-Service W3SVC')
        ->and($result->details['listeners'])->toBe([['port' => 443, 'address' => '0.0.0.0', 'pid' => 4, 'owner' => 'system']]);
})->with(['doctor' => [false], 'install' => [true]]);

it('does not count the portproxy\'s own service, WSL\'s relay or its VM as another program', function (): void {
    $document = networkDocument([
        'portproxy' => [NET_WIFI.' 80 '.NET_WSL.' 80', NET_WIFI.' 443 '.NET_WSL.' 443'],
        'listen' => ['443 '.NET_WIFI.' 3120 svchost', '80 '.NET_WIFI.' 3120 svchost', '443 127.0.0.1 900 wslrelay', '80 0.0.0.0 5000 vmmemWSL'],
    ]);

    expect(networkCheck($this, networkFacts($document, 'engine'))->status)->toBe(CheckStatus::Ok);
});

it('reports a blocking row as a warning in install\'s preflight, so the instance installs with the commands', function (): void {
    $result = networkCheck($this, networkFacts(networkDocument(['rule' => []])), installing: true);

    expect($result->status)->toBe(CheckStatus::Warn)
        ->and($result->details['commands'])->toBe([NET_HOST_RULE]);
});

it('is skipped outside WSL 2 and while phone HTTPS with the local CA is off', function (): void {
    expect(networkCheck($this, networkFacts(networkDocument(), 'desktop', '0'))->status)->toBe(CheckStatus::Skipped)
        ->and(networkCheck($this, new HostFacts)->status)->toBe(CheckStatus::Skipped)
        ->and(networkCheck($this, networkFacts(networkDocument()), null)->status)->toBe(CheckStatus::Skipped);
});

it('never lets a free-form Windows string reach a printed command', function (string $backend, array $replace): void {
    $hostile = "x'; Remove-Item -Recurse C:\\ ; `calc` \$(calc) \" & | > ";
    $document = networkDocument([
        'ipv4' => [NET_WIFI.' Public '.$hostile, '192.168.1.21 Private '.$hostile],
        'listen' => ['8080 0.0.0.0 77 '.$hostile],
        ...$replace,
    ]);
    $document .= "rule=443 2; Remove-Item x\nportproxy=192.168.1.20 443 ".$hostile." 443\n";

    $result = networkCheck($this, networkFacts($document, $backend));
    $allowed = '/^(New-NetFirewall(HyperV)?Rule -Name fieldset-[0-9a-f]{8}-https(-wsl)? |Set-NetConnectionProfile -InterfaceIndex \(Get-NetIPAddress -IPAddress (\d{1,3}\.){3}\d{1,3}\)\.InterfaceIndex -NetworkCategory Private$|netsh interface portproxy (add|delete) v4tov4 (listenaddress=(\d{1,3}\.){3}\d{1,3} )?listenport=\d+( connectaddress=(\d{1,3}\.){3}\d{1,3} connectport=\d+)?$)/D';

    expect($result->details['commands'])->not->toBe([])
        ->and(array_filter($result->details['commands'], static fn (string $c): bool => preg_match($allowed, $c) !== 1))->toBe([])
        ->and($result->hint.$result->summary)->not->toContain('Remove-Item')->not->toContain('calc')->not->toContain('`calc`');
})->with([
    'public profile' => ['desktop', []],
    'unknown rules' => ['desktop', ['ipv4' => [NET_WIFI.' Private x', '192.168.1.21 Private y']]],
    'engine' => ['engine', ['ipv4' => [NET_WIFI.' Private x']]],
]);

it('builds Windows commands only from validated values', function (): void {
    expect(fn () => WindowsCommands::portProxyAdd('192.168.1.20; calc', 80, NET_WSL))->toThrow(InvalidArgumentException::class)
        ->and(fn () => WindowsCommands::privateProfile('192.168.1.256'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => WindowsCommands::portProxyDelete(NET_WIFI, 0))->toThrow(InvalidArgumentException::class)
        ->and(fn () => WindowsCommands::hostRule('x; calc'))->toThrow(InvalidArgumentException::class)
        ->and(WindowsCommands::ruleName(strtoupper(NET_UUID)))->toBe('fieldset-3f1c2b9e-https');
});
