<?php

declare(strict_types=1);

namespace App\Domain\Instance\Doctor\Checks;

use App\Domain\Instance\Compose\Services\EdgeService;
use App\Domain\Instance\Docker\ComposeProject;
use App\Domain\Instance\Docker\ContainerInfo;
use App\Domain\Instance\Docker\DockerCommandFailed;
use App\Domain\Instance\Doctor\CheckResult;
use App\Domain\Instance\Doctor\CheckStatus;
use App\Domain\Instance\Doctor\DoctorContext;
use App\Domain\Instance\Doctor\PortProbe;
use App\Domain\Instance\InstanceException;
use App\Domain\Instance\LocalCa\EdgeBinding;
use App\Domain\Instance\State\InstanceSettings;
use App\Domain\Instance\State\InstanceState;

/**
 * The ports check's local-CA part (R19, ADR 0082): besides the loopback ports of local mode, the edge publishes 80 and
 * 443/tcp on the LAN address only, so both must be free there; when this instance's own edge already runs on that very
 * address (the state's local CA is on it), they are served by it. Docker Engine in WSL 2 with NAT publishes them on
 * every address instead (EdgeBinding, KTD13), and the probe asks there. A new address (`config set lan-address`) is
 * probed like any other, edge or not (M11 gate). Rootless Docker that may not publish them is reported with the
 * sysctl, as in domain mode. The loopback result stays as it was (its chosen ports included) unless the LAN ports fail.
 *
 * `details.lan`: `address`, `ports` (`http` => 80, `https` => 443), `taken`, `served`.
 */
final readonly class LocalCaPorts
{
    public function __construct(private PortProbe $probe) {}

    public function check(DoctorContext $context, string $image, CheckResult $loopback): CheckResult
    {
        $binding = $context->lanBinding() ?? EdgeBinding::recorded((string) $context->lanAddress, null);
        $address = $binding->advertised;
        $where = $binding->where();
        $lan = ['address' => $address, 'ports' => EdgePorts::PORTS, 'taken' => [], 'served' => false];
        if ($loopback->status === CheckStatus::Fail) {
            return new CheckResult($loopback->key, $loopback->status, $loopback->summary, $loopback->hint, [...$loopback->details, 'lan' => $lan]);
        }
        if ($context->installed() && $this->served($context, $binding) && $this->edgeRuns($context)) {
            return $this->merged($loopback, [...$lan, 'served' => true], " Ports 80 and 443 on {$where} are served by this instance's edge.");
        }

        try {
            $taken = array_values(array_filter(EdgePorts::PORTS, fn (int $port): bool => ! $this->probe->isFreeForEdge($binding, $port, $image, $context->uuid)));
        } catch (InstanceException $e) {
            if ($e->errorCode !== 'privileged_ports') {
                throw $e;
            }

            return CheckResult::fail($loopback->key, "Docker cannot publish ports 80 and 443 on {$where} for the edge.", PortProbe::ROOTLESS_HINT, [...$loopback->details, 'lan' => $lan]);
        }
        if ($taken !== []) {
            return CheckResult::fail($loopback->key, 'Port(s) '.implode(', ', $taken)." are in use on {$where}.", "The local CA needs ports 80 and 443 on {$where}: stop the program that uses them, or pick another address with --lan-address.", [...$loopback->details, 'lan' => [...$lan, 'taken' => $taken]]);
        }

        return $this->merged($loopback, $lan, " Ports 80 and 443 on {$where} are free for the edge.");
    }

    /** @param  array<string, mixed>  $lan */
    private function merged(CheckResult $loopback, array $lan, string $summary): CheckResult
    {
        return new CheckResult($loopback->key, $loopback->status, $loopback->summary.$summary, $loopback->hint, [...$loopback->details, 'lan' => $lan], $loopback->code);
    }

    /** The state's local CA is on this address and binding, so a running edge publishes 80 and 443 exactly there. */
    private function served(DoctorContext $context, EdgeBinding $binding): bool
    {
        if (! $context->state instanceof InstanceState) {
            return false;
        }
        $settings = InstanceSettings::of($context->state);
        $recorded = EdgeBinding::fromSettings($settings);

        return $settings->localCa() && $recorded instanceof EdgeBinding && $recorded->equals($binding);
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
