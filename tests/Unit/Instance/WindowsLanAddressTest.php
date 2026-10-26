<?php

declare(strict_types=1);

use App\Domain\Instance\HostFacts;
use App\Domain\Instance\Install\InstallMode;
use App\Domain\Instance\Install\InstallRequest;
use App\Domain\Instance\LocalCa\LanAddress;
use App\Domain\Instance\Output\InvalidInput;
use App\Domain\Instance\Windows\FactsScope;

/*
 * 2.1.1 U6 (R11, R30, KTD8, KTD13): on WSL 2 the Windows LAN addresses are the candidates phones get, never the distro's
 * own interface; `lan-address` (here `--lan-address`) still wins, and without interop nothing is guessed.
 */

const WINDOWS_WIFI = '192.168.1.20';

const WSL_INTERNAL = '172.29.20.5';

/** What a 2.1.1 shim on WSL 2 hands over: no distro addresses as LAN candidates, the Windows document, the distro's own. */
function wslEnvironment(?string $windows, string $lan = ''): array
{
    return array_filter([
        'FIELDSET_WSL' => '2', 'FIELDSET_WSL_BACKEND' => 'engine', 'FIELDSET_LAN_ADDRESSES' => $lan,
        'FIELDSET_WSL_ADDRESSES' => WSL_INTERNAL.' ', 'FIELDSET_WINDOWS_FACTS' => $windows,
    ], is_string(...));
}

function windowsAddressDocument(string $scope = 'addresses'): string
{
    return "\u{FEFF}scope={$scope}\r\n"
        .'ipv4=172.24.0.1 Private vEthernet (WSL) / Hyper-V Virtual Ethernet Adapter'."\r\n"
        .'ipv4=10.8.0.2 Public Corp / TAP-Windows Adapter V9'."\r\n"
        .'ipv4='.WINDOWS_WIFI." Private Wi-Fi / Intel(R) Wi-Fi 6 AX201 160MHz\r\n"
        ."checked=addresses\r\nend=1\r\n";
}

it('takes the Windows LAN addresses on WSL 2, not the distro\'s own, and keeps the distro\'s apart', function (string $scope): void {
    $facts = HostFacts::fromEnvironment(wslEnvironment(windowsAddressDocument($scope), WSL_INTERNAL));

    expect($facts->lanAddresses)->toBe([WINDOWS_WIFI])
        ->and(LanAddress::best($facts))->toBe(WINDOWS_WIFI)
        ->and(LanAddress::isCurrent(WSL_INTERNAL, $facts))->toBeFalse()
        ->and(LanAddress::isCurrent(WINDOWS_WIFI, $facts))->toBeTrue()
        ->and($facts->wslAddresses)->toBe([WSL_INTERNAL])
        ->and($facts->windows?->scope)->toBe(FactsScope::from($scope));
})->with(['addresses', 'full']);

it('knows no LAN address when interop was unavailable or the answer unusable: no guess from WSL\'s own interface', function (string $windows): void {
    $facts = HostFacts::fromEnvironment(wslEnvironment($windows, WSL_INTERNAL));

    expect($facts->lanAddresses)->toBe([])
        ->and(LanAddress::best($facts))->toBeNull()
        ->and(LanAddress::isCurrent(WINDOWS_WIFI, $facts))->toBeNull()
        ->and($facts->wslAddresses)->toBe([WSL_INTERNAL]);
})->with([
    'interop unavailable' => ["scope=unavailable\n"],
    'no end marker' => [str_replace("end=1\r\n", '', windowsAddressDocument())],
    'malformed address line' => [str_replace('ipv4=10.8.0.2', 'ipv4=10.8.0.256', windowsAddressDocument())],
]);

it('keeps the forwarded addresses when the command asked Windows nothing (the detached supervisor\'s child)', function (): void {
    $facts = HostFacts::fromEnvironment(wslEnvironment(null, WINDOWS_WIFI));

    expect($facts->lanAddresses)->toBe([WINDOWS_WIFI])
        ->and($facts->windows?->scope)->toBe(FactsScope::Skipped);
});

it('ignores Windows facts outside WSL 2', function (): void {
    $facts = HostFacts::fromEnvironment([...wslEnvironment(windowsAddressDocument(), '10.0.0.5'), 'FIELDSET_WSL' => '0']);

    expect($facts->lanAddresses)->toBe(['10.0.0.5'])
        ->and($facts->windows)->toBeNull()
        ->and($facts->wslAddresses)->toBe([]);
});

it('lets --lan-address win over the Windows address, and refuses local CA without either when interop is off', function (): void {
    $windows = HostFacts::fromEnvironment(wslEnvironment(windowsAddressDocument('full')));
    $offline = HostFacts::fromEnvironment(wslEnvironment("scope=unavailable\n", WSL_INTERNAL));
    $request = fn (array $options): InstallRequest => InstallRequest::fromOptions(['phone-https' => 'local-ca', ...$options]);
    $localCa = fn (array $options, HostFacts $facts): ?array => InstallMode::localCa($request($options), null, '3f1c2b9e-8a4d-4c1e-9b7a-2d5e6f708192', $facts, static fn (): array => []);

    expect($localCa([], $windows)['lan_address'] ?? null)->toBe(WINDOWS_WIFI)
        ->and($localCa(['lan-address' => '192.168.1.30'], $windows)['lan_address'] ?? null)->toBe('192.168.1.30')
        ->and($localCa(['lan-address' => '192.168.1.30'], $offline)['lan_address'] ?? null)->toBe('192.168.1.30')
        ->and(fn () => $localCa([], $offline))->toThrow(InvalidInput::class, 'No LAN address of this host is known for the local CA.');
});
