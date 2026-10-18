<?php

declare(strict_types=1);

namespace App\Domain\Instance\Config\Appliers;

use App\Domain\Instance\Compose\EdgeNetwork;
use App\Domain\Instance\Docker\ComposeProject;
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
use App\Domain\Instance\InstanceException;
use App\Domain\Instance\Lifecycle\AppCommands;
use App\Domain\Instance\Output\ExitCode;
use App\Domain\Instance\State\InputGrammar;
use App\Domain\Instance\State\InstanceDirectory;
use App\Domain\Instance\State\InstanceSettings;
use App\Domain\Instance\State\InstanceState;
use App\Domain\Instance\Tunnel\ExposureCheck;

/**
 * The checks before the local-CA edge starts or moves to another LAN address (R19, R29, ADR 0081, ADR 0082), under the
 * lock and before anything changes (exit 3):
 *
 * - ports 80 and 443 free on the LAN address, unless this instance's edge already serves them (LocalCaPorts);
 * - when the edge is new: no account signs in with the demo password `password`, demo or not. `config set` cannot hand
 *   out new passwords, so demo accounts are rotated first with `fsa demo --rotate-passwords` (ADR 0081);
 * - the edge network's subnet: the recorded one, one a removed edge network left behind, or a new one (ADR 0075).
 */
final readonly class LocalCaPreflight
{
    public function __construct(
        private InstanceDirectory $directory,
        private DockerGateway $docker,
        private DoctorProbes $probes,
        private PortProbe $ports,
        private AppCommands $app,
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
        if (! InstanceSettings::of($before)->localCa()) {
            $this->refuseDefaultPasswords($before);
        }

        return $settings->edgeSubnet() === null ? $after->withSetting('edge_subnet', $this->subnet($after)) : $after;
    }

    private function refuseDefaultPasswords(InstanceState $state): void
    {
        $exposure = ExposureCheck::run($this->app, ComposeProject::forInstance($state, $this->directory));
        $exposure->refuseOtherDefaults('the local CA');
        if ($exposure->demoDefaults !== []) {
            throw new InstanceException(
                count($exposure->demoDefaults).' demo account(s) still sign in with the password "password": the local CA would make them reachable from your network.',
                ExitCode::PreflightFailed,
                'default_password_in_use',
                'Rotate them first with `fsa demo --rotate-passwords` (it lists the new passwords once), then run the command again.',
                false,
                ['users' => $exposure->demoDefaults],
            );
        }
    }

    private function subnet(InstanceState $state): string
    {
        $left = $this->docker->inspectNetwork($state->project().'_'.EdgeNetwork::NAME);
        $reusable = $left instanceof NetworkInfo ? array_values(array_filter($left->subnets, InputGrammar::isPrivateSubnet(...))) : [];

        return $reusable[0] ?? EdgeNetwork::choose($state->uuid(), $this->docker->networkSubnets());
    }
}
