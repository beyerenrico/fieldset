<?php

declare(strict_types=1);

namespace App\Domain\Instance\Windows;

/**
 * What the launcher learned from Windows through WSL interop (2.1.1 U6, KTD8, KTD9), validated by WindowsFactsParser.
 * Every fact has an explicit unknown: an enum's Unknown case, a Presence of Unknown, or a null list. Facts the scope
 * did not ask for are unknown, and so is everything when interop was unavailable (R30). Free-form Windows strings
 * (adapter, rule and process names) never get here: only addresses, ports, PIDs and enums do, so the doctor can build
 * PowerShell commands from them (KTD9).
 *
 * The edge ports' rules: `hostRule()` asks for an enabled inbound allow rule of the Windows firewall for the TCP port
 * that covers the Private profile; `hyperVRule()` for an enabled inbound allow rule of the Hyper-V firewall for WSL's
 * VM.
 */
final readonly class WindowsFacts
{
    /** Profile bit of NetSecurity's Profile flags (Any = 0, Domain = 1, Private = 2, Public = 4). */
    private const int PRIVATE_PROFILE = 2;

    /**
     * @param  list<WindowsAddress>|null  $addresses  the LAN candidates in Windows' order; null: unknown
     * @param  list<array{port: int, profiles: int}>|null  $hostRules  enabled inbound allow rules for TCP 80/443
     * @param  list<int>|null  $hyperVRules  ports of enabled inbound allow Hyper-V rules for WSL's VM
     * @param  list<PortProxyEntry>|null  $portProxies
     * @param  list<WindowsListener>|null  $listeners  Windows listeners on 80 and 443
     * @param  WslIdleTimeouts|null  $idleTimeouts  `.wslconfig`'s idle timeouts (2.1.1 U7); null: unknown
     */
    public function __construct(
        public FactsScope $scope,
        public ?array $addresses = null,
        public WslNetworking $networking = WslNetworking::Unknown,
        public PortBinding $portBinding = PortBinding::Unknown,
        private ?array $hostRules = null,
        public HyperVFirewall $hyperVFirewall = HyperVFirewall::Unknown,
        public InboundAction $hyperVInbound = InboundAction::Unknown,
        private ?array $hyperVRules = null,
        public ?array $portProxies = null,
        public ?array $listeners = null,
        public ?WslIdleTimeouts $idleTimeouts = null,
    ) {}

    public static function skipped(): self
    {
        return new self(FactsScope::Skipped);
    }

    public static function unavailable(): self
    {
        return new self(FactsScope::Unavailable);
    }

    /** Whether the launcher asked Windows at all (false: no interop call was meant to happen). */
    public function asked(): bool
    {
        return $this->scope !== FactsScope::Skipped;
    }

    /**
     * The Windows LAN addresses phones can reach (they replace the distro's own on WSL 2), or null when unknown.
     *
     * @return list<string>|null
     */
    public function lanAddresses(): ?array
    {
        return $this->addresses === null ? null : array_map(static fn (WindowsAddress $a): string => $a->address, $this->addresses);
    }

    /** The profile of the network an address is on; Unknown for an address Windows did not report. */
    public function profile(string $address): NetworkProfile
    {
        foreach ($this->addresses ?? [] as $candidate) {
            if ($candidate->address === $address) {
                return $candidate->profile;
            }
        }

        return NetworkProfile::Unknown;
    }

    public function hostRule(int $port): Presence
    {
        if ($this->hostRules === null) {
            return Presence::Unknown;
        }

        return array_any($this->hostRules, static fn (array $rule): bool => $rule['port'] === $port
            && ($rule['profiles'] === 0 || ($rule['profiles'] & self::PRIVATE_PROFILE) !== 0)) ? Presence::Present : Presence::Missing;
    }

    public function hyperVRule(int $port): Presence
    {
        if ($this->hyperVRules === null) {
            return Presence::Unknown;
        }

        return in_array($port, $this->hyperVRules, true) ? Presence::Present : Presence::Missing;
    }
}
