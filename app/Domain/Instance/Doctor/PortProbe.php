<?php

declare(strict_types=1);

namespace App\Domain\Instance\Doctor;

use App\Domain\Instance\Docker\ContainerSpec;
use App\Domain\Instance\Docker\DockerGateway;
use App\Domain\Instance\Docker\Labels;
use App\Domain\Instance\InstanceException;
use App\Domain\Instance\Output\ExitCode;

/**
 * Whether a loopback port is free, as the Docker engine sees it (§6.2): the ops container cannot see the host's
 * sockets, so it publishes a throwaway container on the port and reads the engine's answer. The container runs
 * `true` from the app image (already on the host) and is removed; it carries the instance label, so uninstall
 * sweeps it up even if a failed start left it behind (R63).
 */
final readonly class PortProbe
{
    public const string ROLE = 'port-probe';

    /** Linux engine (another container / another process) and Docker Desktop wordings. */
    private const string TAKEN = '/port is already allocated|address already in use|ports are not available/i';

    /** Rootless Docker (RootlessKit) refusing a port below net.ipv4.ip_unprivileged_port_start, in its own words. */
    private const string ROOTLESSKIT = '/privileged port|ip_unprivileged_port_start|rootlesskit/i';

    /** A bare refusal to bind: counts as "privileged" only for a host-wide probe of a port below 1024. */
    private const string DENIED = '/permission denied/i';

    /** How a rootless Docker host lets RootlessKit publish 80 and 443. */
    public const string ROOTLESS_HINT = 'Rootless Docker publishes ports below 1024 only with `sudo sysctl net.ipv4.ip_unprivileged_port_start=80` (persist it in /etc/sysctl.d/), or run domain mode on a rootful Docker engine.';

    public function __construct(private DockerGateway $docker) {}

    public function isFree(int $port, string $image, ?string $uuid = null): bool
    {
        return $this->probe("127.0.0.1:{$port}:8080", $port, $image, $uuid, false);
    }

    /**
     * Whether a port is free on every IPv4 address of the host (with `$ipv6` on every IPv6 address), as the edge
     * publishes 80 and 443 (domain mode). Rootless Docker that may not bind it is reported as such (exit 3
     * `privileged_ports`), not as "in use".
     */
    public function isFreeOnHost(int $port, string $image, ?string $uuid = null, bool $ipv6 = false): bool
    {
        return $this->probe(($ipv6 ? '[::]' : '0.0.0.0').":{$port}:8080", $port, $image, $uuid, true);
    }

    /** Whether a port is free on one host address (local-CA mode publishes 80 and 443 on the LAN address only). */
    public function isFreeOnAddress(string $address, int $port, string $image, ?string $uuid = null): bool
    {
        return $this->probe("{$address}:{$port}:8080", $port, $image, $uuid, true);
    }

    private function probe(string $binding, int $port, string $image, ?string $uuid, bool $hostWide): bool
    {
        $labels = $uuid === null ? [Labels::ROLE => self::ROLE] : Labels::forInstance($uuid, self::ROLE);
        $outcome = $this->docker->runContainer(new ContainerSpec(
            image: $image,
            labels: $labels,
            ports: [$binding],
            entrypoint: 'true',
        ), null, 60.0);
        if ($outcome->successful()) {
            return true;
        }
        if (preg_match(self::TAKEN, $outcome->stderr) === 1) {
            return false;
        }
        if (preg_match(self::ROOTLESSKIT, $outcome->stderr) === 1 || ($hostWide && $port < 1024 && preg_match(self::DENIED, $outcome->stderr) === 1)) {
            throw new InstanceException(
                "Docker may not publish port {$port}: ".$outcome->stderrTail(2),
                ExitCode::PreflightFailed,
                'privileged_ports',
                self::ROOTLESS_HINT,
            );
        }

        throw new InstanceException(
            "Cannot probe port {$port}: ".$outcome->stderrTail(2),
            ExitCode::PreflightFailed,
            'port_probe_failed',
            'Check that Docker can start containers from the app image, then run the command again.',
        );
    }
}
