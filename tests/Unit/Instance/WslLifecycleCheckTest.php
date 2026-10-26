<?php

declare(strict_types=1);

use App\Domain\Instance\Doctor\CheckResult;
use App\Domain\Instance\Doctor\Checks\WslLifecycleCheck;
use App\Domain\Instance\Doctor\CheckStatus;
use App\Domain\Instance\Doctor\DoctorContext;
use App\Domain\Instance\HostFacts;
use App\Domain\Instance\Windows\WindowsFactsParser;
use App\Domain\Instance\Windows\WslIdleTimeouts;
use Tests\Support\Instance\InstanceCommandFixture;
use Tests\Support\Instance\TemporaryInstance;

/*
 * 2.1.1 U7 (R31, R8): Docker Engine in WSL 2 stops with the distro when the last terminal closes (WSL's idle timeout)
 * and does not start with it without systemd; the doctor warns with the exact setting lines. Docker Desktop gets no Engine
 * warnings, only what is known about it: it starts after the Windows sign-in, and the idle-distro finding is unverified.
 */

beforeEach(function (): void {
    $this->fixture = new InstanceCommandFixture;
});

afterEach(fn () => TemporaryInstance::cleanup());

/** What a 2.1.1 shim on WSL 2 hands over for doctor: `.wslconfig`'s idle timeouts in the Windows facts, systemd as PID 1. */
function lifecycleFacts(string $backend, ?string $wslconfig, ?string $systemd, string $wsl = '2'): HostFacts
{
    $document = $wslconfig === null ? "scope=unavailable\n" : "scope=full\nnetworking=nat\n{$wslconfig}checked=wslconfig\nend=1\n";

    return HostFacts::fromEnvironment(array_filter([
        'FIELDSET_WSL' => $wsl, 'FIELDSET_WSL_BACKEND' => $backend, 'FIELDSET_WINDOWS_FACTS' => $document, 'FIELDSET_WSL_SYSTEMD' => $systemd,
    ], is_string(...)));
}

function lifecycleCheck(object $test, HostFacts $facts): CheckResult
{
    return new WslLifecycleCheck()->run(new DoctorContext($facts, $test->fixture->directory, $test->fixture->docker, $test->fixture->probes));
}

it('passes on Engine with the idle timeout off and systemd as PID 1', function (): void {
    $result = lifecycleCheck($this, lifecycleFacts('engine', "instanceidletimeout=-1\nvmidletimeout=60000\n", '1'));

    expect($result->status)->toBe(CheckStatus::Ok, $result->summary)
        ->and($result->details)->toMatchArray(['backend' => 'engine', 'instance_idle_timeout' => -1, 'vm_idle_timeout' => 60000, 'wslconfig' => 'read', 'systemd' => true]);
});

it('warns on Engine with the default or a set idle timeout, with the .wslconfig lines', function (string $wslconfig, ?int $timeout): void {
    $result = lifecycleCheck($this, lifecycleFacts('engine', $wslconfig, '1'));

    expect($result->status)->toBe(CheckStatus::Warn)
        ->and($result->summary)->toContain('stops when its last terminal closes')
        ->and($result->hint)->toContain("%UserProfile%\\.wslconfig on Windows:\n    [general]\n    instanceIdleTimeout=-1\n")
        ->and($result->hint)->toContain('`wsl --shutdown`')->not->toContain('systemd=true')
        ->and($result->details['instance_idle_timeout'])->toBe($timeout);
})->with([
    'not set (the default)' => ['', null],
    'set to a minute' => ["instanceidletimeout=60000\n", 60000],
]);

it('warns on Engine without systemd, with the wsl.conf lines and wsl --shutdown', function (): void {
    $result = lifecycleCheck($this, lifecycleFacts('engine', "instanceidletimeout=-1\n", '0'));

    expect($result->status)->toBe(CheckStatus::Warn)
        ->and($result->summary)->toContain('does not start with the distro')
        ->and($result->hint)->toContain("/etc/wsl.conf in the distro:\n    [boot]\n    systemd=true\n")
        ->and($result->hint)->toContain('`wsl --shutdown`')->not->toContain('instanceIdleTimeout');
});

it('names both fixes in one warning when both are missing', function (): void {
    $result = lifecycleCheck($this, lifecycleFacts('engine', '', '0'));

    expect($result->status)->toBe(CheckStatus::Warn)
        ->and($result->hint)->toContain('instanceIdleTimeout=-1')->toContain('systemd=true')
        ->and(substr_count((string) $result->hint, 'wsl --shutdown'))->toBe(1);
});

it('gives Docker Desktop no Engine warnings: the sign-in note and the unverified idle-distro finding, as a pass', function (): void {
    $result = lifecycleCheck($this, lifecycleFacts('desktop', '', '0'));

    expect($result->status)->toBe(CheckStatus::Ok)
        ->and($result->summary)->toContain('starts only after you sign in to Windows')->toContain('unknown until verified')
        ->and($result->summary)->not->toContain('instanceIdleTimeout')
        ->and($result->details)->toMatchArray(['backend' => 'desktop', 'idle_distro' => 'unknown']);
});

it('reports unknown on Engine when .wslconfig could not be read (interop off) or systemd was not reported', function (?string $wslconfig, ?string $systemd): void {
    $result = lifecycleCheck($this, lifecycleFacts('engine', $wslconfig, $systemd));

    expect($result->status)->toBe(CheckStatus::Unknown)
        ->and($result->code)->toBe(WslLifecycleCheck::UNKNOWN)
        ->and($result->hint)->toContain('instanceIdleTimeout=-1')->toContain('systemd=true');
})->with([
    'interop off' => [null, '1'],
    'systemd not reported' => ["instanceidletimeout=-1\n", null],
]);

it('still warns about systemd when .wslconfig could not be read', function (): void {
    $result = lifecycleCheck($this, lifecycleFacts('engine', null, '0'));

    expect($result->status)->toBe(CheckStatus::Warn)
        ->and($result->details['wslconfig'])->toBe('unknown')
        ->and($result->summary)->toContain('could not be read');
});

it('is skipped outside WSL 2', function (): void {
    expect(lifecycleCheck($this, lifecycleFacts('engine', '', '0', '0'))->status)->toBe(CheckStatus::Skipped)
        ->and(lifecycleCheck($this, new HostFacts)->status)->toBe(CheckStatus::Skipped);
});

it('reads the idle timeouts only from a .wslconfig section that ran to its end, and only as integers', function (): void {
    $read = fn (string $lines): ?WslIdleTimeouts => WindowsFactsParser::parse("scope=full\n{$lines}end=1\n")->idleTimeouts;

    expect($read("instanceidletimeout=-1\nvmidletimeout=60000\nchecked=wslconfig\n"))->toEqual(new WslIdleTimeouts(-1, 60000))
        ->and($read("checked=wslconfig\n"))->toEqual(new WslIdleTimeouts(null, null))
        ->and($read("instanceidletimeout=-1\n"))->toBeNull()
        ->and($read("instanceidletimeout=-1; calc\nchecked=wslconfig\n"))->toBeNull()
        ->and($read("instanceidletimeout=-1\ninstanceidletimeout=0\nchecked=wslconfig\n"))->toBeNull()
        ->and(WindowsFactsParser::parse("scope=addresses\ninstanceidletimeout=-1\nchecked=wslconfig\nend=1\n")->idleTimeouts)->toBeNull();
});

it('reads systemd as PID 1 from FIELDSET_WSL_SYSTEMD on WSL 2 only', function (): void {
    $systemd = fn (array $env): ?bool => HostFacts::fromEnvironment($env)->wslSystemd;

    expect($systemd(['FIELDSET_WSL' => '2', 'FIELDSET_WSL_SYSTEMD' => '1']))->toBeTrue()
        ->and($systemd(['FIELDSET_WSL' => '2', 'FIELDSET_WSL_SYSTEMD' => '0']))->toBeFalse()
        ->and($systemd(['FIELDSET_WSL' => '2', 'FIELDSET_WSL_SYSTEMD' => '']))->toBeNull()
        ->and($systemd(['FIELDSET_WSL' => '2', 'FIELDSET_WSL_SYSTEMD' => 'yes']))->toBeNull()
        ->and($systemd(['FIELDSET_WSL' => '0', 'FIELDSET_WSL_SYSTEMD' => '1']))->toBeNull();
});
