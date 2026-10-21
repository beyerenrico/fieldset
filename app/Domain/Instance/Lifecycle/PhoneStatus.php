<?php

declare(strict_types=1);

namespace App\Domain\Instance\Lifecycle;

use App\Domain\Instance\Doctor\Checks\LanAddressCheck;
use App\Domain\Instance\HostFacts;
use App\Domain\Instance\LocalCa\LanAddress;
use App\Domain\Instance\LocalCa\LocalCaDeletion;
use App\Domain\Instance\LocalCa\LocalCaInfo;
use App\Domain\Instance\State\InstanceDirectory;
use App\Domain\Instance\State\InstanceState;
use App\Domain\Instance\Tunnel\TunnelStatus;

/**
 * The phone-HTTPS part of `fieldset status` (R19, R20, AE6, ADR 0082), read-only: the local CA (LAN address, trust page,
 * root fingerprint, whether the address is still the host's) and the quick tunnel (active, expired or stopped). An
 * expired tunnel is reported, never removed here: the next mutating command does that (TunnelSweeper).
 */
final readonly class PhoneStatus
{
    public function __construct(private InstanceDirectory $directory, private TunnelStatus $tunnel, private HostFacts $facts) {}

    /**
     * @return array{data: array{phone_https: array<string, mixed>|null, tunnel: array<string, mixed>|null}, warnings: list<array{0: string, 1: string, 2: string|null}>}
     */
    public function of(InstanceState $state): array
    {
        $localCa = LocalCaInfo::data($state, $this->directory, $this->facts);
        $tunnel = $this->tunnel->of($state);
        $warnings = [];
        if ($localCa !== null && $localCa['lan_address_current'] === false) {
            $warnings[] = [LanAddressCheck::WARNING, "The local CA's certificate is for {$localCa['lan_address']}, which is no longer an address of this host.", LanAddressCheck::hint(LanAddress::best($this->facts))];
        }
        if ($tunnel['state'] === 'expired') {
            $warnings[] = ['tunnel_expired', "The quick tunnel {$tunnel['hostname']} expired at {$tunnel['expires_at']}; its address answers 503.", 'The next fieldset command that changes something removes it; `fieldset tunnel start` starts a new one, `fieldset tunnel stop` removes it now.'];
        }
        if ($tunnel['state'] === 'stopped') {
            $warnings[] = ['tunnel_stopped', 'The quick tunnel does not run any more (cloudflared is not restarted: a restart would get a new address).', 'The next fieldset command that changes something removes it; `fieldset tunnel start` starts a new one.'];
        }
        if (LocalCaDeletion::pending($state)) {
            $warnings[] = ['local_ca_delete_pending', 'The local CA turned off earlier could not be deleted: its keys are still on the edge\'s certificate volume.', 'Run `'.LocalCaDeletion::RETRY_COMMAND.'` to delete it.'];
        }

        return ['data' => ['phone_https' => $localCa ?? ($tunnel['state'] === 'none' ? null : ['mode' => 'tunnel']), 'tunnel' => $tunnel['state'] === 'none' ? null : $tunnel], 'warnings' => $warnings];
    }
}
