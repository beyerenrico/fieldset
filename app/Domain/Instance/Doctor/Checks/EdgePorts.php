<?php

declare(strict_types=1);

namespace App\Domain\Instance\Doctor\Checks;

use App\Domain\Instance\Compose\Services\EdgeService;
use App\Domain\Instance\Docker\ComposeProject;
use App\Domain\Instance\Docker\ContainerInfo;
use App\Domain\Instance\Docker\DockerCommandFailed;
use App\Domain\Instance\Doctor\CheckResult;
use App\Domain\Instance\Doctor\DoctorContext;
use App\Domain\Instance\Doctor\PortProbe;
use App\Domain\Instance\InstanceException;
use App\Domain\Instance\State\InstanceState;

/**
 * The ports check in domain mode (R10, U8; ADR 0075): the edge publishes 80 and 443 on every host address, so both
 * must be free there (443/udp for HTTP/3 is not probed): on 0.0.0.0, and on [::] when the host has an IPv6 address
 * (FSA_LAN_ADDRESSES; ADR 0078), where a listener on IPv6 only would otherwise make the edge fail to start. An engine
 * that cannot bind [::] at all (IPv6 off) leaves IPv6 unprobed instead of failing the check. A rootless Docker engine
 * that may not publish ports below 1024 is reported as that, with the sysctl that fixes it, before any port is called
 * "in use". On an installed instance whose own edge runs, its ports are served by it and not probed.
 *
 * `details`: `ports` (`http` => 80, `https` => 443), `taken`, `rootless`, `ipv6` (whether [::] was probed).
 */
final readonly class EdgePorts
{
    public const array PORTS = ['http' => 80, 'https' => 443];

    public function __construct(private PortProbe $probe) {}

    public function check(DoctorContext $context, string $image, string $key): CheckResult
    {
        $rootless = $context->info()->rootless;
        $ipv6 = $this->hostHasIpv6($context->facts->lanAddresses);
        $details = ['ports' => self::PORTS, 'taken' => [], 'rootless' => $rootless, 'ipv6' => $ipv6];
        if ($context->installed() && $this->edgeRuns($context)) {
            return CheckResult::ok($key, 'Ports 80 and 443 are served by this instance\'s edge.', $details);
        }
        $taken = [];
        foreach (self::PORTS as $port) {
            try {
                if (! $this->probe->isFreeOnHost($port, $image, $context->uuid) || ($ipv6 && ! $this->freeOnIpv6($port, $image, $context, $details))) {
                    $taken[] = $port;
                }
            } catch (InstanceException $e) {
                if ($e->errorCode !== 'privileged_ports') {
                    throw $e;
                }

                return CheckResult::fail($key, ($rootless ? 'Rootless Docker cannot' : 'Docker cannot').' publish port '.$port.' for the edge.', PortProbe::ROOTLESS_HINT, $details);
            }
        }
        if ($taken !== []) {
            return CheckResult::fail($key, 'Port(s) '.implode(', ', $taken).' are in use on this host.', 'Domain mode needs ports 80 and 443 for the edge: stop the web server or instance that uses them, then run the command again.', [...$details, 'taken' => $taken]);
        }

        return CheckResult::ok($key, 'Ports 80 and 443 are free for the edge'.($rootless ? ' (rootless Docker may publish them).' : '.'), $details);
    }

    /** @param  list<string>  $addresses */
    private function hostHasIpv6(array $addresses): bool
    {
        return array_any($addresses, static fn (string $address): bool => filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false && $address !== '::1');
    }

    /**
     * [::] probe; an engine that cannot bind IPv6 at all (`port_probe_failed`) marks IPv6 as not probed, not taken.
     *
     * @param  array<string, mixed>  $details
     */
    private function freeOnIpv6(int $port, string $image, DoctorContext $context, array &$details): bool
    {
        if ($details['ipv6'] !== true) {
            return true;
        }

        try {
            return $this->probe->isFreeOnHost($port, $image, $context->uuid, ipv6: true);
        } catch (InstanceException $e) {
            if ($e->errorCode !== 'port_probe_failed') {
                throw $e;
            }
            $details['ipv6'] = false;

            return true;
        }
    }

    private function edgeRuns(DoctorContext $context): bool
    {
        if (! $context->state instanceof InstanceState) {
            return false;
        }

        try {
            $containers = $context->docker->composePs(ComposeProject::forInstance($context->state, $context->directory));
        } catch (DockerCommandFailed) {
            return false;
        }

        return array_any($containers, static fn (ContainerInfo $container): bool => $container->running() && $container->service() === EdgeService::NAME);
    }
}
