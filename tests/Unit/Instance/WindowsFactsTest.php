<?php

declare(strict_types=1);

use App\Domain\Instance\Windows\FactsScope;
use App\Domain\Instance\Windows\HyperVFirewall;
use App\Domain\Instance\Windows\InboundAction;
use App\Domain\Instance\Windows\ListenerOwner;
use App\Domain\Instance\Windows\NetworkProfile;
use App\Domain\Instance\Windows\PortBinding;
use App\Domain\Instance\Windows\PortProxyEntry;
use App\Domain\Instance\Windows\Presence;
use App\Domain\Instance\Windows\WindowsFacts;
use App\Domain\Instance\Windows\WindowsFactsParser;
use App\Domain\Instance\Windows\WindowsListener;
use App\Domain\Instance\Windows\WslNetworking;

/*
 * 2.1.1 U6 (R11, R30, KTD8, KTD9): the Windows facts the shim collects through one powershell.exe call, validated into
 * typed facts with explicit unknowns. Free-form Windows strings (adapter and process names) never come out.
 */

/**
 * A document as the shim hands it over: its scope line, the script's lines, the end marker.
 *
 * @param  array<string, list<string>|null>  $replace  lines by key that replace the defaults (null drops them)
 */
function windowsDocument(array $replace = [], string $scope = 'full', bool $end = true): string
{
    $lines = [
        'ipv4' => [
            '192.168.1.20 Private Wi-Fi / Intel(R) Wi-Fi 6 AX201 160MHz',
            '172.29.16.1 Public vEthernet (WSL (Hyper-V firewall)) / Hyper-V Virtual Ethernet Adapter',
            '10.8.0.2 Public Corp / TAP-Windows Adapter V9',
            '10.2.0.2 Private ProtonVPN / WireGuard Tunnel',
            '100.101.102.103 Public Tailscale / Tailscale Tunnel',
            '10.5.0.9 Private Ethernet 3 / Cisco AnyConnect Secure Mobility Client Virtual Miniport Adapter',
        ],
        'networking' => ['nat'],
        'binding' => ['default-port-binding'],
        'rule' => ['443 2', '80 4'],
        'hyperv_enabled' => ['True'],
        'hyperv_inbound' => ['Block'],
        'hyperv_rule' => ['443'],
        'portproxy' => ['192.168.1.20 443 172.29.20.5 443', '* 80 wsl.local 80'],
        'listen' => ['443 0.0.0.0 4 System', '80 :: 5120 com.docker.backend'],
        'checked' => ['addresses', 'rules', 'hyperv_rules', 'portproxy', 'listen'],
        ...$replace,
    ];
    $text = "scope={$scope}\n";
    foreach (array_filter($lines, is_array(...)) as $key => $values) {
        foreach ($values as $value) {
            $text .= "{$key}={$value}\n";
        }
    }

    return $end ? $text."end=1\n" : $text;
}

it('reads every fact the doctor uses from a full document', function (): void {
    $facts = WindowsFactsParser::parse(windowsDocument());

    expect($facts->scope)->toBe(FactsScope::Full)
        ->and($facts->lanAddresses())->toBe(['192.168.1.20'])
        ->and($facts->profile('192.168.1.20'))->toBe(NetworkProfile::Private)
        ->and($facts->profile('172.29.16.1'))->toBe(NetworkProfile::Unknown)
        ->and($facts->networking)->toBe(WslNetworking::Nat)
        ->and($facts->portBinding)->toBe(PortBinding::Default)
        ->and($facts->hostRule(443))->toBe(Presence::Present)
        ->and($facts->hostRule(80))->toBe(Presence::Missing)
        ->and($facts->hyperVFirewall)->toBe(HyperVFirewall::Enabled)
        ->and($facts->hyperVInbound)->toBe(InboundAction::Block)
        ->and($facts->hyperVRule(443))->toBe(Presence::Present)
        ->and($facts->hyperVRule(80))->toBe(Presence::Missing)
        ->and($facts->portProxies)->toEqual([new PortProxyEntry('192.168.1.20', 443, '172.29.20.5', 443), new PortProxyEntry(null, 80, null, 80)])
        ->and($facts->listeners)->toEqual([new WindowsListener(443, '0.0.0.0', 4, ListenerOwner::System), new WindowsListener(80, '::', 5120, ListenerOwner::Docker)])
        ->and($facts->asked())->toBeTrue();
});

it('chooses the Wi-Fi address over vEthernet, VPN and tunnel adapters, and keeps Windows\' order without duplicates', function (): void {
    $facts = WindowsFactsParser::parse(windowsDocument(['ipv4' => [
        '172.24.0.1 Private vEthernet (WSL) / Hyper-V Virtual Ethernet Adapter',
        '10.0.0.7 Private Ethernet / Realtek PCIe GbE Family Controller',
        '192.168.178.40 Private WLAN / Intel(R) Wi-Fi 6E AX211 160MHz',
        '10.0.0.7 Private Ethernet / Realtek PCIe GbE Family Controller',
        '203.0.113.9 Public Ethernet 2 / USB modem',
        '10.64.0.2 Private OpenVPN Data Channel Offload / OpenVPN Data Channel Offload',
        '172.31.0.5 Private Docker / Docker NAT',
        '192.168.56.1 Private Ethernet 4 / VirtualBox Host-Only Ethernet Adapter',
    ]]));

    expect($facts->lanAddresses())->toBe(['10.0.0.7', '192.168.178.40']);
});

it('parses CRLF line ends and a UTF-8 byte order mark the same as plain lines', function (): void {
    $plain = windowsDocument();

    expect(WindowsFactsParser::parse("\u{FEFF}".str_replace("\n", "\r\n", $plain)))->toEqual(WindowsFactsParser::parse($plain))
        ->and(WindowsFactsParser::parse("\u{FEFF}".str_replace("\n", "\r", $plain))->lanAddresses())->toBe(['192.168.1.20']);
});

it('drops hostile adapter and process names: they never reach a fact', function (): void {
    $hostile = 'Wi-Fi"; Remove-Item -Recurse C:\\; `$(whoami)` & calc \'x\'';
    $facts = WindowsFactsParser::parse(windowsDocument([
        'ipv4' => ["192.168.1.20 Private {$hostile} / Intel(R) Wi-Fi", "10.8.0.2 Private {$hostile} / TAP-Windows Adapter V9"],
        'listen' => ["443 0.0.0.0 7788 {$hostile}"],
    ]));
    $dump = serialize($facts);

    expect($facts->lanAddresses())->toBe(['192.168.1.20'])
        ->and($facts->listeners)->toEqual([new WindowsListener(443, '0.0.0.0', 7788, ListenerOwner::Other)])
        ->and($dump)->not->toContain('Remove-Item')->not->toContain('whoami')->not->toContain('`')->not->toContain('calc')->not->toContain('Wi-Fi');
});

it('makes a list unknown when one of its lines is malformed: never a partial address list', function (array $replace, string $unknown): void {
    $facts = WindowsFactsParser::parse(windowsDocument($replace));
    $lists = ['addresses' => $facts->lanAddresses(), 'portproxy' => $facts->portProxies, 'listen' => $facts->listeners];

    match ($unknown) {
        'rules' => expect($facts->hostRule(443))->toBe(Presence::Unknown)->and($facts->hostRule(80))->toBe(Presence::Unknown),
        'hyperv_rules' => expect($facts->hyperVRule(443))->toBe(Presence::Unknown),
        default => expect($lists[$unknown])->toBeNull(),
    };
    expect($facts->scope)->toBe(FactsScope::Full)->and($facts->networking)->toBe(WslNetworking::Nat);
})->with([
    'octet above 255' => [['ipv4' => ['192.168.1.20 Private Wi-Fi / x', '192.168.1.256 Private Ethernet / y']], 'addresses'],
    'leading zero' => [['ipv4' => ['192.168.1.20 Private Wi-Fi / x', '192.168.01.21 Private Ethernet / y']], 'addresses'],
    'an IPv6 address' => [['ipv4' => ['192.168.1.20 Private Wi-Fi / x', 'fe80::1 Private Ethernet / y']], 'addresses'],
    'empty address' => [['ipv4' => ['192.168.1.20 Private Wi-Fi / x', '']], 'addresses'],
    'address section did not finish' => [['checked' => ['rules', 'hyperv_rules', 'portproxy', 'listen']], 'addresses'],
    'rule port out of range' => [['rule' => ['443 2', '70000 2']], 'rules'],
    'rule profile not a number' => [['rule' => ['443 Private']], 'rules'],
    'rule section did not finish' => [['checked' => ['addresses', 'hyperv_rules', 'portproxy', 'listen']], 'rules'],
    'Hyper-V rule port as a range' => [['hyperv_rule' => ['80-90']], 'hyperv_rules'],
    'portproxy with shell syntax' => [['portproxy' => ['192.168.1.20 443 $(calc) 443']], 'portproxy'],
    'portproxy without ports' => [['portproxy' => ['192.168.1.20 http 172.29.20.5 https']], 'portproxy'],
    'listener PID not a number' => [['listen' => ['443 0.0.0.0 abc System']], 'listen'],
    'listener address not an IP' => [['listen' => ['443 localhost 4 System']], 'listen'],
]);

it('keeps an empty list known when the section finished without lines', function (): void {
    $facts = WindowsFactsParser::parse(windowsDocument(['ipv4' => null, 'rule' => null, 'hyperv_rule' => null, 'portproxy' => null, 'listen' => null]));

    expect($facts->lanAddresses())->toBe([])
        ->and($facts->hostRule(443))->toBe(Presence::Missing)
        ->and($facts->hyperVRule(443))->toBe(Presence::Missing)
        ->and($facts->portProxies)->toBe([])
        ->and($facts->listeners)->toBe([]);
});

it('turns values outside their sets, and a key given twice with different values, into unknown', function (): void {
    $facts = WindowsFactsParser::parse(windowsDocument([
        'ipv4' => ['192.168.1.20 private;calc Wi-Fi / x'],
        'networking' => ['virtioproxy'],
        'binding' => ['local-only-port-binding', 'default-port-binding'],
        'hyperv_enabled' => ['yes'],
        'hyperv_inbound' => [''],
    ]));

    expect($facts->lanAddresses())->toBe(['192.168.1.20'])
        ->and($facts->profile('192.168.1.20'))->toBe(NetworkProfile::Unknown)
        ->and($facts->networking)->toBe(WslNetworking::Unknown)
        ->and($facts->portBinding)->toBe(PortBinding::Unknown)
        ->and($facts->hyperVFirewall)->toBe(HyperVFirewall::Unknown)
        ->and($facts->hyperVInbound)->toBe(InboundAction::Unknown);
});

it('reads each enum value it accepts', function (): void {
    $read = fn (array $replace): WindowsFacts => WindowsFactsParser::parse(windowsDocument($replace));

    expect($read(['networking' => ['mirrored']])->networking)->toBe(WslNetworking::Mirrored)
        ->and($read(['binding' => ['local-only-port-binding']])->portBinding)->toBe(PortBinding::LocalOnly)
        ->and($read(['binding' => ['default-local-port-binding']])->portBinding)->toBe(PortBinding::DefaultLocal)
        ->and($read(['hyperv_enabled' => ['False'], 'hyperv_inbound' => ['Allow']])->hyperVFirewall)->toBe(HyperVFirewall::Disabled)
        ->and($read(['hyperv_enabled' => ['NotConfigured'], 'hyperv_inbound' => ['Allow']])->hyperVInbound)->toBe(InboundAction::Allow)
        ->and($read(['ipv4' => ['192.168.1.20 Public Wi-Fi / x']])->profile('192.168.1.20'))->toBe(NetworkProfile::Public)
        ->and($read(['ipv4' => ['192.168.1.20 DomainAuthenticated Ethernet / x']])->profile('192.168.1.20'))->toBe(NetworkProfile::DomainAuthenticated)
        ->and($read(['rule' => ['80 0', '443 6']])->hostRule(80))->toBe(Presence::Present)
        ->and($read(['rule' => ['80 0', '443 6']])->hostRule(443))->toBe(Presence::Present)
        ->and($read(['rule' => ['80 1']])->hostRule(80))->toBe(Presence::Missing)
        ->and($read(['listen' => ['80 127.0.0.1 900 wslrelay', '443 0.0.0.0 1200 svchost', '443 :: 3000 httpd']])->listeners)->toEqual([
            new WindowsListener(80, '127.0.0.1', 900, ListenerOwner::Wsl),
            new WindowsListener(443, '0.0.0.0', 1200, ListenerOwner::Service),
            new WindowsListener(443, '::', 3000, ListenerOwner::Other),
        ]);
});

it('knows only the addresses after the cheap address query', function (): void {
    $facts = WindowsFactsParser::parse(windowsDocument(scope: 'addresses'));

    expect($facts->scope)->toBe(FactsScope::Addresses)
        ->and($facts->lanAddresses())->toBe(['192.168.1.20'])
        ->and($facts->networking)->toBe(WslNetworking::Unknown)
        ->and($facts->portBinding)->toBe(PortBinding::Unknown)
        ->and($facts->hostRule(443))->toBe(Presence::Unknown)
        ->and($facts->hyperVRule(443))->toBe(Presence::Unknown)
        ->and($facts->portProxies)->toBeNull()
        ->and($facts->listeners)->toBeNull();
});

it('makes everything unknown when interop was unavailable or the answer is incomplete or malformed', function (?string $document, FactsScope $scope): void {
    $facts = WindowsFactsParser::parse($document);

    expect($facts->scope)->toBe($scope)
        ->and($facts->lanAddresses())->toBeNull()
        ->and($facts->networking)->toBe(WslNetworking::Unknown)
        ->and($facts->hostRule(443))->toBe(Presence::Unknown)
        ->and($facts->listeners)->toBeNull();
})->with([
    'no call made' => [null, FactsScope::Skipped],
    'empty' => ["\r\n", FactsScope::Skipped],
    'interop unavailable' => ["scope=unavailable\n", FactsScope::Unavailable],
    'no end marker (timed out mid-way)' => [windowsDocument(end: false), FactsScope::Unavailable],
    'no scope line' => [substr(windowsDocument(), strlen("scope=full\n")), FactsScope::Unavailable],
    'unknown scope' => [windowsDocument(scope: 'partial'), FactsScope::Unavailable],
    'two scopes' => ["scope=addresses\n".windowsDocument(), FactsScope::Unavailable],
    'PowerShell error text only' => ["scope=full\nGet-NetIPConfiguration : Access is denied.\r\n+ CategoryInfo : PermissionDenied\r\n", FactsScope::Unavailable],
    'UTF-16 output' => [mb_convert_encoding(windowsDocument(), 'UTF-16LE', 'UTF-8'), FactsScope::Unavailable],
    'oversized' => [windowsDocument(['ipv4' => array_fill(0, 3000, '192.168.1.20 Private Wi-Fi / Intel(R) Wi-Fi 6 AX201 160MHz')]), FactsScope::Unavailable],
]);

it('ignores keys it does not know and lines of other shapes', function (): void {
    $facts = WindowsFactsParser::parse(str_replace("end=1\n", "future_fact=1\nWARNING: something\n  ipv4=10.0.0.1 Private Eth / x\nend=1\n", windowsDocument()));

    expect($facts->scope)->toBe(FactsScope::Full)->and($facts->lanAddresses())->toBe(['192.168.1.20']);
});
