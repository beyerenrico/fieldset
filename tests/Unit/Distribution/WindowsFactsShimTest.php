<?php

declare(strict_types=1);

use App\Domain\Instance\HostFacts;
use App\Domain\Instance\LocalCa\LanAddress;
use App\Domain\Instance\State\InstanceState;
use App\Domain\Instance\Windows\FactsScope;
use App\Domain\Instance\Windows\Presence;
use App\Domain\Instance\Windows\WslNetworking;
use Tests\Support\Docker\DistributionScripts;

/*
 * 2.1.1 U6 (R11, R30, KTD8, KTD13): on WSL 2 the shim asks Windows through one powershell.exe call, for every fact on
 * install, doctor, uninstall and `config set phone-https|lan-address`, only for the LAN addresses on other commands while
 * phones use the local CA, and not at all otherwise. The distro's own addresses never become LAN candidates there; without
 * interop the facts are "unavailable". Hosts outside WSL keep their `ip` candidates and never call powershell.exe.
 */

const FACTS_SHIM_IMAGE = 'ghcr.io/beyerenrico/fieldset@sha256:1111111111111111111111111111111111111111111111111111111111111111';

const FACTS_WSL_ADDRESS = '172.29.20.5';

/** What powershell.exe prints: a BOM, CRLF line ends, a terminal colour code and PowerShell's error text among the facts. */
const FACTS_POWERSHELL_OUTPUT = "\u{FEFF}ipv4=172.24.0.1 Private vEthernet (WSL) / Hyper-V Virtual Ethernet Adapter\r\n"
    ."ipv4=10.8.0.2 Public Corp / TAP-Windows Adapter V9\r\n"
    ."ipv4=192.168.1.20 Private Wi-Fi / Intel(R) Wi-Fi 6 AX201\r\n"
    ."checked=addresses\r\n"
    ."Get-NetFirewallRule : Access is denied.\r\n"
    ."networking=\e[31mnat\r\n"
    ."rule=443 2\r\nchecked=rules\r\n"
    ."end=1\r\n";

beforeEach(function (): void {
    $this->scripts = new DistributionScripts;
    mkdir($this->scripts->root.'/home');
    $this->dir = $this->scripts->root.'/home/fieldset';
    mkdir($this->dir, 0700);
    factsShimState($this->dir);
    copy(base_path('distribution/shim.sh'), $this->dir.'/fieldset');
    chmod($this->dir.'/fieldset', 0700);
    $this->scripts->writeExecutable('ip', "#!/bin/sh\nprintf '%s\\n' '2: eth0    inet ".FACTS_WSL_ADDRESS."/20 brd 172.29.31.255 scope global eth0' '3: docker0    inet 172.17.0.1/16 scope global docker0'\n");
    $this->scripts->writeExecutable('powershell.exe', "#!/bin/sh\nfor a in \"\$@\"; do printf '%s\\037' \"\$a\"; done >> \"\$STUB_ROOT/powershell-calls\"\nprintf '\\036' >> \"\$STUB_ROOT/powershell-calls\"\n[ ! -f \"\$STUB_ROOT/powershell-output\" ] || cat \"\$STUB_ROOT/powershell-output\"\n");
    file_put_contents($this->scripts->root.'/powershell-output', FACTS_POWERSHELL_OUTPUT);
    $this->env = ['STUB_UNAME' => 'Linux', 'STUB_DOCKER_INFO' => 'Ubuntu 24.04 LTS|host|[]'];
});

afterEach(fn () => $this->scripts->cleanUp());

/** @param  array<string, string>  $settings */
function factsShimState(string $dir, array $settings = []): void
{
    $state = InstanceState::create('3f1c2b9e-8a4d-4c1e-9b7a-2d5e6f708192', $dir, 1000, 1000, 1000)
        ->withRelease('2.1.1', ['app' => FACTS_SHIM_IMAGE, 'postgres' => 'docker.io/library/postgres@sha256:'.str_repeat('2', 64)]);
    file_put_contents($dir.'/state.json', ($settings === [] ? $state : $state->withSettings($settings))->toJson());
}

/** @return list<list<string>> the argv of every powershell.exe call */
function factsShimCalls(DistributionScripts $scripts): array
{
    $log = (string) @file_get_contents($scripts->root.'/powershell-calls');

    return array_map(static fn (string $call): array => explode("\x1f", rtrim($call, "\x1f")), array_values(array_filter(explode("\x1e", $log))));
}

it('collects every Windows fact in one call for install, doctor, uninstall and config set phone-https|lan-address', function (array $arguments): void {
    $this->scripts->wsl();

    $process = $this->scripts->run($this->dir.'/fieldset', $arguments, $this->env);
    $env = $this->scripts->opsEnvironment();
    $calls = factsShimCalls($this->scripts);
    $facts = HostFacts::fromEnvironment($env);

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
        ->and($calls)->toHaveCount(1)
        ->and(array_slice($calls[0], 0, 3))->toBe(['-NoProfile', '-NonInteractive', '-Command'])
        ->and($calls[0][3])->toStartWith('$f=1; $ErrorActionPreference=\'Stop\';')->toEndWith("'end=1' ")->not->toContain("\n")->not->toContain('"')
        ->and($env['FIELDSET_WINDOWS_FACTS'])->toBe("scope=full\nipv4=172.24.0.1 Private vEthernet (WSL) / Hyper-V Virtual Ethernet Adapter\n"
            ."ipv4=10.8.0.2 Public Corp / TAP-Windows Adapter V9\nipv4=192.168.1.20 Private Wi-Fi / Intel(R) Wi-Fi 6 AX201\n"
            ."checked=addresses\nnetworking=[31mnat\nrule=443 2\nchecked=rules\nend=1")
        ->and($env['FIELDSET_LAN_ADDRESSES'])->toBe('')
        ->and($env['FIELDSET_WSL_ADDRESSES'])->toBe(FACTS_WSL_ADDRESS.' ')
        ->and(implode(' ', $this->scripts->opsRuns()[0]))->toContain('-e FIELDSET_WINDOWS_FACTS ', '-e FIELDSET_WSL_ADDRESSES ')
        ->and($facts->windows?->scope)->toBe(FactsScope::Full)
        ->and(LanAddress::best($facts))->toBe('192.168.1.20')
        ->and($facts->wslAddresses)->toBe([FACTS_WSL_ADDRESS])
        ->and($facts->windows?->networking)->toBe(WslNetworking::Unknown)
        ->and($facts->windows?->hostRule(443))->toBe(Presence::Present);
})->with([
    'install' => [['install']],
    'doctor' => [['doctor', '--json']],
    'uninstall' => [['uninstall', '--yes']],
    'config set phone-https' => [['config', 'set', 'phone-https', 'local-ca', '--yes']],
    'config set lan-address, flags first' => [['--json', 'config', '--yes', 'set', 'lan-address', '192.168.1.30']],
]);

it('makes no interop call when the command needs no Windows facts, and guesses no LAN address from WSL\'s own', function (array $arguments): void {
    $this->scripts->wsl();

    $process = $this->scripts->run($this->dir.'/fieldset', $arguments, $this->env);
    $env = $this->scripts->opsEnvironment();

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
        ->and(factsShimCalls($this->scripts))->toBe([])
        ->and($env['FIELDSET_WINDOWS_FACTS'])->toBe('')
        ->and($env['FIELDSET_LAN_ADDRESSES'])->toBe('')
        ->and($env['FIELDSET_WSL_ADDRESSES'])->toBe(FACTS_WSL_ADDRESS.' ')
        ->and(HostFacts::fromEnvironment($env)->lanAddresses)->toBe([]);
})->with([
    'status' => [['status']],
    'logs' => [['logs', 'app']],
    'config show' => [['config', 'show']],
    'config set of another key' => [['config', 'set', 'mail', 'capture']],
    'a flag value that looks like the key' => [['config', 'set', '--smtp-host', 'phone-https', 'mail', 'smtp']],
    'phone-https as a value, not the key' => [['config', 'set', 'operator-email', 'phone-https']],
]);

it('asks only for the addresses while phones use the local CA', function (): void {
    $this->scripts->wsl();
    factsShimState($this->dir, ['phone_https' => 'local-ca', 'lan_address' => '192.168.1.20', 'edge_subnet' => '10.213.4.32/28']);

    $process = $this->scripts->run($this->dir.'/fieldset', ['status'], $this->env);
    $env = $this->scripts->opsEnvironment();
    $calls = factsShimCalls($this->scripts);

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
        ->and($calls)->toHaveCount(1)
        ->and($calls[0][3])->toStartWith('$f=0; ')
        ->and($env['FIELDSET_WINDOWS_FACTS'])->toStartWith("scope=addresses\nipv4=")
        ->and(HostFacts::fromEnvironment($env)->lanAddresses)->toBe(['192.168.1.20']);
});

it('reports the facts as unavailable without interop or powershell.exe, and guesses no address', function (?string $marker, bool $powershell): void {
    $this->scripts->wsl(DistributionScripts::WSL2_KERNEL, in_array($marker, [null, 'disabled'], true) ? null : $marker);
    if ($marker === 'disabled') {
        $this->scripts->proc('sys/fs/binfmt_misc/WSLInterop', "disabled\ninterpreter /init\n");
    }
    if (! $powershell) {
        unlink($this->scripts->bin.'/powershell.exe');
    }

    $process = $this->scripts->run($this->dir.'/fieldset', ['doctor'], [...$this->env, 'WSL_DISTRO_NAME' => 'Ubuntu']);
    $env = $this->scripts->opsEnvironment();
    $facts = HostFacts::fromEnvironment($env);

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
        ->and(factsShimCalls($this->scripts))->toBe([])
        ->and($env['FIELDSET_WINDOWS_FACTS'])->toBe('scope=unavailable')
        ->and($env['FIELDSET_LAN_ADDRESSES'])->toBe('')
        ->and($facts->windows?->scope)->toBe(FactsScope::Unavailable)
        ->and($facts->lanAddresses)->toBe([])
        ->and(LanAddress::best($facts))->toBeNull();
})->with([
    'interop switched off (entry disabled)' => ['disabled', true],
    'no interop entry' => [null, true],
    'powershell.exe neither on PATH nor at the Windows path' => ['WSLInterop', false],
]);

it('bounds the call with timeout(1) where it exists, and hands over what powershell.exe printed before it ended', function (): void {
    $this->scripts->wsl();
    $this->scripts->writeExecutable('timeout', "#!/bin/sh\nprintf '%s' \"\$1\" > \"\$STUB_ROOT/timeout-seconds\"\nshift\n\"\$@\"\nexit 124\n");
    file_put_contents($this->scripts->root.'/powershell-output', "ipv4=192.168.1.20 Private Wi-Fi / x\r\nchecked=addresses\r\n");

    $process = $this->scripts->run($this->dir.'/fieldset', ['doctor'], $this->env);
    $facts = HostFacts::fromEnvironment($this->scripts->opsEnvironment());

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
        ->and((string) file_get_contents($this->scripts->root.'/timeout-seconds'))->toBe('60')
        ->and(factsShimCalls($this->scripts))->toHaveCount(1)
        ->and($facts->windows?->scope)->toBe(FactsScope::Unavailable)
        ->and($facts->lanAddresses)->toBe([]);
});

it('keeps the ip candidates and never calls powershell.exe outside WSL', function (): void {
    $process = $this->scripts->run($this->dir.'/fieldset', ['doctor'], $this->env);
    $env = $this->scripts->opsEnvironment();

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
        ->and(factsShimCalls($this->scripts))->toBe([])
        ->and($env['FIELDSET_LAN_ADDRESSES'])->toBe(FACTS_WSL_ADDRESS.' ')
        ->and($env['FIELDSET_WINDOWS_FACTS'])->toBe('')
        ->and($env['FIELDSET_WSL_ADDRESSES'])->toBe('')
        ->and(HostFacts::fromEnvironment($env)->lanAddresses)->toBe([FACTS_WSL_ADDRESS]);
});
