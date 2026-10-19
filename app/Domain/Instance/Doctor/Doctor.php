<?php

declare(strict_types=1);

namespace App\Domain\Instance\Doctor;

use App\Domain\Instance\Docker\DockerUnavailable;
use App\Domain\Instance\Doctor\Checks\ArchitectureCheck;
use App\Domain\Instance\Doctor\Checks\ClockCheck;
use App\Domain\Instance\Doctor\Checks\DiskCheck;
use App\Domain\Instance\Doctor\Checks\DnsCheck;
use App\Domain\Instance\Doctor\Checks\DockerVersionCheck;
use App\Domain\Instance\Doctor\Checks\LanAddressCheck;
use App\Domain\Instance\Doctor\Checks\MemoryCheck;
use App\Domain\Instance\Doctor\Checks\PathShareCheck;
use App\Domain\Instance\Doctor\Checks\PermissionsCheck;
use App\Domain\Instance\Doctor\Checks\PortsCheck;
use App\Domain\Instance\Doctor\Checks\ProxyAddressCheck;
use App\Domain\Instance\Doctor\Checks\RegistryCheck;
use App\Domain\Instance\Doctor\Checks\ServicesCheck;
use App\Domain\Instance\Doctor\Checks\StaleFilesCheck;
use App\Domain\Instance\InstanceException;
use Throwable;

/**
 * `fsa doctor` and install's preflight (R10, ADR 0072). Checks run in a fixed order (registry before clock, which
 * reuses its answer); a check that throws counts as failed, except an unreachable Docker engine, which is exit 10
 * for the whole command.
 */
final readonly class Doctor
{
    public function __construct(private PortProbe $ports) {}

    public function run(DoctorContext $context): DoctorReport
    {
        $results = [];
        foreach ($this->checks($context) as $check) {
            try {
                $results[] = $check->run($context);
            } catch (DockerUnavailable $e) {
                throw $e;
            } catch (Throwable $e) {
                $hint = $e instanceof InstanceException ? $e->hint : null;
                $results[] = CheckResult::fail($check->key(), $e->getMessage(), $hint ?? 'Run `fsa doctor` again; if it keeps failing, check the Docker engine.');
            }
        }

        return new DoctorReport($results);
    }

    /** @return list<DoctorCheck> */
    public function checks(DoctorContext $context): array
    {
        return [
            new DockerVersionCheck, new ArchitectureCheck, new MemoryCheck, new DiskCheck, new PathShareCheck,
            new PermissionsCheck, new StaleFilesCheck, new DnsCheck, new RegistryCheck, new ClockCheck,
            new PortsCheck($this->ports), new ProxyAddressCheck, new LanAddressCheck, ...($context->post ? [new ServicesCheck] : []),
        ];
    }
}
