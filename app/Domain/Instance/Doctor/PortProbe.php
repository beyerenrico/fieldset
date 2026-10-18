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

    public function __construct(private DockerGateway $docker) {}

    public function isFree(int $port, string $image, ?string $uuid = null): bool
    {
        $labels = $uuid === null ? [Labels::ROLE => self::ROLE] : Labels::forInstance($uuid, self::ROLE);
        $outcome = $this->docker->runContainer(new ContainerSpec(
            image: $image,
            labels: $labels,
            ports: ["127.0.0.1:{$port}:8080"],
            entrypoint: 'true',
        ), null, 60.0);
        if ($outcome->successful()) {
            return true;
        }
        if (preg_match(self::TAKEN, $outcome->stderr) === 1) {
            return false;
        }

        throw new InstanceException(
            "Cannot probe port {$port}: ".$outcome->stderrTail(2),
            ExitCode::PreflightFailed,
            'port_probe_failed',
            'Check that Docker can start containers from the app image, then run the command again.',
        );
    }
}
