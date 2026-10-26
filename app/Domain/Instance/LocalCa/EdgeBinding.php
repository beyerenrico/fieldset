<?php

declare(strict_types=1);

namespace App\Domain\Instance\LocalCa;

use App\Domain\Instance\Docker\WslBackend;
use App\Domain\Instance\HostFacts;
use App\Domain\Instance\State\InstanceSettings;
use App\Domain\Instance\State\InstanceState;
use App\Domain\Instance\Windows\WslNetworking;

/**
 * Where the local-CA edge publishes 80 and 443/tcp (2.1.1 U6, KTD13). The advertised address (the certificate's SAN,
 * the phone URL and QR code, the doctor's checks, the `lan-address` setting) is the LAN address. The bind address is
 * the host IP Docker publishes on: the same address, except for Docker Engine in WSL 2 with NAT networking, where the
 * Windows LAN address does not exist inside the distro. There the edge publishes on every address of the distro (the
 * target of the Windows portproxy the doctor suggests); so it does when Engine's networking mode is unknown, as binding
 * the Windows address fails under NAT. Docker Desktop and Engine with mirrored networking bind the advertised address,
 * and so does every host outside WSL.
 *
 * The decision is made where the LAN address is chosen: install, `config set phone-https` and `config set lan-address`,
 * which collect full Windows facts (KTD8). It is recorded as the setting `lan_bind: all`, so every later render
 * publishes the same way without asking Windows; a state outside WSL never gets the setting.
 */
final readonly class EdgeBinding
{
    public const string SETTING = 'lan_bind';

    /** `lan_bind`: the edge publishes on every address of the Docker host. */
    public const string ALL = 'all';

    /** @param  string|null  $bindAddress  null: every address of the Docker host */
    private function __construct(public string $advertised, public ?string $bindAddress) {}

    /** What this host's setup calls for (KTD13). */
    public static function decide(string $advertised, HostFacts $facts): self
    {
        $engineBehindNat = $facts->onWsl2() && $facts->wslBackend === WslBackend::Engine && $facts->wslNetworking() !== WslNetworking::Mirrored;

        return new self($advertised, $engineBehindNat ? null : $advertised);
    }

    /** As a state records it: on the LAN address unless `lan_bind` is `all`. */
    public static function recorded(string $advertised, mixed $bind): self
    {
        return new self($advertised, $bind === self::ALL ? null : $advertised);
    }

    public static function fromSettings(InstanceSettings $settings): ?self
    {
        $address = $settings->lanAddress();

        return $address === null ? null : self::recorded($address, $settings->lanBind());
    }

    public function bindsAll(): bool
    {
        return $this->bindAddress === null;
    }

    /** The bind address in words, for messages. */
    public function where(): string
    {
        return $this->bindAddress ?? 'every address of the Docker host';
    }

    public function equals(self $other): bool
    {
        return $this->advertised === $other->advertised && $this->bindAddress === $other->bindAddress;
    }

    /**
     * The settings that record it on top of a state (none yet: a fresh install): `lan_bind` only where it changes, so a
     * state outside WSL keeps exactly its settings.
     *
     * @return array{lan_bind?: string|null}
     */
    public function settingsFor(?InstanceState $state): array
    {
        $wanted = $this->bindsAll() ? self::ALL : null;
        $current = $state instanceof InstanceState ? InstanceSettings::of($state)->lanBind() : null;

        return $current === $wanted ? [] : [self::SETTING => $wanted];
    }
}
