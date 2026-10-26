<?php

declare(strict_types=1);

namespace App\Domain\Instance\Windows;

use App\Domain\Instance\Docker\WslBackend;

/**
 * How phones reach a WSL 2 instance's edge (2.1.1 KTD10, KTD13): through Docker Desktop's port forwarding on Windows,
 * through a Windows portproxy to Docker Engine behind WSL's NAT, or through WSL's mirrored networking and the Hyper-V
 * firewall. Unknown when the engine or Engine's networking mode is.
 */
enum WslSetup: string
{
    case DockerDesktop = 'desktop';
    case EngineNat = 'engine-nat';
    case EngineMirrored = 'engine-mirrored';
    case Unknown = 'unknown';

    public static function of(?WslBackend $backend, WslNetworking $networking): self
    {
        return match (true) {
            $backend === WslBackend::DockerDesktop => self::DockerDesktop,
            $backend === WslBackend::Engine && $networking === WslNetworking::Nat => self::EngineNat,
            $backend === WslBackend::Engine && $networking === WslNetworking::Mirrored => self::EngineMirrored,
            default => self::Unknown,
        };
    }
}
