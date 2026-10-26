<?php

declare(strict_types=1);

namespace App\Domain\Instance\Docker;

/**
 * Which Docker engine a WSL 2 distro reaches (2.1.1 U2). The shim reads it from `docker info`'s OperatingSystem by
 * prefix, as DockerInfo::wslBackend() does, and passes it as FIELDSET_WSL_BACKEND next to FIELDSET_WSL=2.
 *
 * - desktop: Docker Desktop on Windows through its WSL integration (OperatingSystem "Docker Desktop")
 * - engine: Docker Engine installed in the distro (OperatingSystem names the distro, e.g. "Ubuntu 24.04 LTS", on newer
 *   WSL with " (containerized)" after it)
 */
enum WslBackend: string
{
    case DockerDesktop = 'desktop';
    case Engine = 'engine';

    /** WslScriptsTest pins this against the shim's `case` on OperatingSystem, so both read a backend the same way. */
    public static function fromOperatingSystem(string $operatingSystem): self
    {
        return str_starts_with($operatingSystem, 'Docker Desktop') ? self::DockerDesktop : self::Engine;
    }
}
