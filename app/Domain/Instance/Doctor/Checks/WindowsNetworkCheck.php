<?php

declare(strict_types=1);

namespace App\Domain\Instance\Doctor\Checks;

use App\Domain\Instance\Doctor\CheckResult;
use App\Domain\Instance\Doctor\DoctorCheck;
use App\Domain\Instance\Doctor\DoctorContext;
use App\Domain\Instance\LocalCa\EdgeBinding;
use App\Domain\Instance\LocalCa\LanAddress;
use App\Domain\Instance\Windows\ListenerOwner;
use App\Domain\Instance\Windows\PhoneAccessHint;
use App\Domain\Instance\Windows\PhoneAccessRow;
use App\Domain\Instance\Windows\PhoneAccessRows;
use App\Domain\Instance\Windows\PortProxyEntry;
use App\Domain\Instance\Windows\WindowsFacts;
use App\Domain\Instance\Windows\WindowsListener;
use App\Domain\Instance\Windows\WslSetup;

/**
 * Phone access with the local CA on WSL 2 (2.1.1 U14, R12, R30, KTD9, KTD10): whether Windows lets phones reach the edge,
 * from the facts the shim collected through interop (WindowsFacts). In this order:
 *
 * - skipped outside WSL 2 and while phone HTTPS with the local CA is off;
 * - fail when another program listens on 80 or 443 on Windows where phones connect (IIS and other HTTP.sys services,
 *   PID 4): Docker Desktop's forwarding, WSL's relay and the portproxy that serves this instance do not count;
 * - warn `lan_address_changed` when the certificate's address (the advertised address, KTD13) is none of Windows' LAN
 *   addresses now: on WSL this check makes that comparison instead of `lan_address`;
 * - otherwise the first matching row of the hint table (PhoneAccessRows): fail with one copy-ready command set under
 *   "Run in PowerShell as administrator:", `unknown` (code `windows_facts_unknown`) when the facts it needs are unknown,
 *   ok when nothing blocks. Install's preflight reports a blocking row as a warning, so the instance installs and the
 *   commands are printed with its result; the doctor fails. fieldset never runs the commands.
 *
 * `details`: `address`, `setup` (WslSetup), `facts` (FactsScope), `row` (PhoneAccessRow or null), `commands` (the printed
 * lines), `unknown` (facts Windows did not report); `listeners` for a port in use, `candidates` for a changed address.
 */
final readonly class WindowsNetworkCheck implements DoctorCheck
{
    public const string UNKNOWN = 'windows_facts_unknown';

    public function key(): string
    {
        return 'windows_network';
    }

    public function run(DoctorContext $context): CheckResult
    {
        if ($context->facts->wslVersion !== 2) {
            return CheckResult::skipped($this->key(), 'Not a WSL 2 host: no Windows firewall between phones and the edge.');
        }
        $binding = $context->lanBinding();
        if (! $binding instanceof EdgeBinding) {
            return CheckResult::skipped($this->key(), 'Phone HTTPS with the local CA is off.');
        }
        $facts = $context->facts->windows ?? WindowsFacts::skipped();
        $address = $binding->advertised;
        $setup = WslSetup::of($context->facts->wslBackend, $facts->networking);
        $details = ['address' => $address, 'setup' => $setup->value, 'facts' => $facts->scope->value, 'row' => null, 'commands' => [], 'unknown' => []];
        $foreign = $this->foreignListeners($facts, $address);
        if ($foreign !== []) {
            return $this->portInUse($foreign, $address, $details);
        }
        $current = $facts->lanAddresses();
        if ($current !== null && ! in_array($address, $current, true)) {
            $best = LanAddress::best($context->facts);

            return CheckResult::warn($this->key(), "The local CA's certificate is for {$address}, which is not one of Windows' LAN addresses now.", LanAddressCheck::hint($best), [...$details, 'candidates' => $current], LanAddressCheck::WARNING);
        }

        return $this->result(PhoneAccessRows::evaluate($facts, $setup, $address, $context->facts->wslAddresses, (string) $context->uuid), $details, $context->selectPorts);
    }

    /** @param  array<string, mixed>  $details */
    private function result(PhoneAccessHint $hint, array $details, bool $installing): CheckResult
    {
        $details = [...$details, 'row' => $hint->row?->value, 'commands' => $hint->commands, 'unknown' => $hint->unknown];

        return match (true) {
            ! $hint->row instanceof PhoneAccessRow => CheckResult::ok($this->key(), $hint->summary, $details),
            $hint->row === PhoneAccessRow::FactsUnknown => CheckResult::unknown($this->key(), $hint->summary, (string) $hint->hint(), $details, self::UNKNOWN),
            $installing => CheckResult::warn($this->key(), $hint->summary, (string) $hint->hint(), $details),
            default => CheckResult::fail($this->key(), $hint->summary, (string) $hint->hint(), $details),
        };
    }

    /**
     * Windows listeners on an edge port where phones connect (every address or the advertised one) that are neither
     * Docker Desktop's forwarding, WSL's relay, nor a service while a portproxy listens on that port.
     *
     * @return list<WindowsListener>
     */
    private function foreignListeners(WindowsFacts $facts, string $address): array
    {
        $proxied = array_map(static fn (PortProxyEntry $entry): int => $entry->listenPort, $facts->portProxies ?? []);

        return array_values(array_filter($facts->listeners ?? [], static fn (WindowsListener $l): bool => in_array($l->address, ['0.0.0.0', '::', $address], true)
            && in_array($l->port, EdgePorts::PORTS, true)
            && match ($l->owner) {
                ListenerOwner::Docker, ListenerOwner::Wsl => false,
                ListenerOwner::Service => ! in_array($l->port, $proxied, true),
                ListenerOwner::System, ListenerOwner::Other => true,
            }));
    }

    /**
     * @param  non-empty-list<WindowsListener>  $listeners
     * @param  array<string, mixed>  $details
     */
    private function portInUse(array $listeners, string $address, array $details): CheckResult
    {
        $ports = array_values(array_unique(array_map(static fn (WindowsListener $l): int => $l->port, $listeners)));
        $who = array_values(array_unique(array_map(static fn (WindowsListener $l): string => match ($l->owner) {
            ListenerOwner::System => 'the Windows HTTP server (PID 4: IIS or another HTTP.sys service)',
            ListenerOwner::Service => "a Windows service (svchost, PID {$l->pid})",
            default => "another program (PID {$l->pid})",
        }, $listeners)));
        $summary = (count($ports) === 1 ? 'Port '.$ports[0].' is' : 'Ports '.implode(' and ', $ports).' are').' in use on Windows by '.implode(' and ', $who).", so phones cannot reach the edge at https://{$address}.";
        $hint = 'Free ports 80 and 443 on Windows: stop IIS (`Stop-Service W3SVC` in PowerShell as administrator) or the program with that PID (Task Manager, Details), then run `fieldset doctor` again.';
        $found = array_map(static fn (WindowsListener $l): array => ['port' => $l->port, 'address' => $l->address, 'pid' => $l->pid, 'owner' => $l->owner->value], $listeners);

        return CheckResult::fail($this->key(), $summary, $hint, [...$details, 'listeners' => $found]);
    }
}
