<?php

declare(strict_types=1);

namespace App\Domain\Instance\Config\Appliers;

use App\Domain\Instance\Compose\EdgeNetwork;
use App\Domain\Instance\Docker\DockerGateway;
use App\Domain\Instance\Docker\NetworkInfo;
use App\Domain\Instance\Doctor\CheckResult;
use App\Domain\Instance\Doctor\Checks\LocalCaPorts;
use App\Domain\Instance\Doctor\DoctorContext;
use App\Domain\Instance\Doctor\DoctorFailed;
use App\Domain\Instance\Doctor\DoctorProbes;
use App\Domain\Instance\Doctor\DoctorReport;
use App\Domain\Instance\Doctor\PortProbe;
use App\Domain\Instance\HostFacts;
use App\Domain\Instance\State\InputGrammar;
use App\Domain\Instance\State\InstanceDirectory;
use App\Domain\Instance\State\InstanceSettings;
use App\Domain\Instance\State\InstanceState;

/**
 * The checks before the local-CA edge starts or moves to another LAN address (R19, R29, ADR 0081, ADR 0082), under the
 * lock and before anything changes (exit 3):
 *
 * - ports 80 and 443 free on the LAN address, unless this instance's edge already serves them (LocalCaPorts);
 * - the edge network's subnet: the recorded one, one a removed edge network left behind, or a new one (ADR 0075).
 *
 * The demo-password rule (no account on `password` when the edge is new) is ConfigSetter's, for every change that
 * widens the instance's reach (DefaultPasswordGuard, ADR 0081).
 */
final readonly class LocalCaPreflight
{
    public function __construct(
        private InstanceDirectory $directory,
        private DockerGateway $docker,
        private DoctorProbes $probes,
        private PortProbe $ports,
    ) {}

    /** The target with the edge subnet set. */
    public function check(InstanceState $before, InstanceState $after, HostFacts $facts): InstanceState
    {
        $settings = InstanceSettings::of($after);
        $address = (string) $settings->lanAddress();
        $context = new DoctorContext($facts, $this->directory, $this->docker, $this->probes, $before, null, $before->digests()['app'] ?? null, uuid: $before->uuid(), lanAddress: $address);
        $ports = new LocalCaPorts($this->ports)->check($context, (string) $context->appImage, CheckResult::ok('ports', 'Loopback ports are this instance\'s.'));
        if (! new DoctorReport([$ports])->passed()) {
            throw new DoctorFailed(new DoctorReport([$ports]));
        }

        return $settings->edgeSubnet() === null ? $after->withSetting('edge_subnet', $this->subnet($after)) : $after;
    }

    private function subnet(InstanceState $state): string
    {
        $left = $this->docker->inspectNetwork($state->project().'_'.EdgeNetwork::NAME);
        $reusable = $left instanceof NetworkInfo ? array_values(array_filter($left->subnets, InputGrammar::isPrivateSubnet(...))) : [];

        return $reusable[0] ?? EdgeNetwork::choose($state->uuid(), $this->docker->networkSubnets());
    }
}
