<?php

declare(strict_types=1);

namespace App\Domain\Instance\Windows;

/**
 * Who listens on an edge port on Windows, from the owning process: the process name itself is free-form and goes no
 * further than this classification (KTD9).
 *
 * - docker: Docker Desktop's port forwarding (com.docker.backend and friends), e.g. this instance's own edge
 * - wsl: WSL's localhost relay
 * - system: PID 4, the kernel's HTTP server (http.sys: IIS, WinRM, other HTTP.sys services)
 * - service: a service host (svchost, e.g. the IP Helper that serves `netsh interface portproxy`)
 * - other: any other program
 */
enum ListenerOwner: string
{
    case Docker = 'docker';
    case Wsl = 'wsl';
    case System = 'system';
    case Service = 'service';
    case Other = 'other';

    public static function fromProcess(int $pid, string $name): self
    {
        return match (true) {
            $pid === 4 || strcasecmp($name, 'System') === 0 => self::System,
            preg_match('/^(com\.docker\.|docker|vpnkit)/i', $name) === 1 => self::Docker,
            preg_match('/^(wslrelay|wslhost|wslservice)$/i', $name) === 1 => self::Wsl,
            strcasecmp($name, 'svchost') === 0 => self::Service,
            default => self::Other,
        };
    }
}
