<?php

declare(strict_types=1);

namespace App\Domain\Instance\Config\Appliers;

use App\Domain\Instance\Docker\DockerGateway;
use App\Domain\Instance\Docker\DockerUnavailable;
use App\Domain\Instance\Doctor\CheckResult;
use App\Domain\Instance\Doctor\Checks\DnsCheck;
use App\Domain\Instance\Doctor\Checks\PortsCheck;
use App\Domain\Instance\Doctor\DoctorContext;
use App\Domain\Instance\Doctor\DoctorFailed;
use App\Domain\Instance\Doctor\DoctorProbes;
use App\Domain\Instance\Doctor\DoctorReport;
use App\Domain\Instance\Doctor\PortProbe;
use App\Domain\Instance\HostFacts;
use App\Domain\Instance\InstanceException;
use App\Domain\Instance\State\InstanceDirectory;
use App\Domain\Instance\State\InstanceState;
use Throwable;

/**
 * Domain mode's preflight for `config set domain` (R10, AE5, ADR 0075): the install's DNS check for the new domain
 * (A and AAAA against the host's addresses; FSA_TEST_DNS in harness images) and its ports check, which needs 80
 * and 443 free on the host unless this instance's own edge already serves them. A failure is exit 3 with the
 * results under `data.checks`, before anything changes; warnings come back for the result.
 */
final readonly class EdgePreflight
{
    public function __construct(
        private InstanceDirectory $directory,
        private DockerGateway $docker,
        private DoctorProbes $probes,
        private PortProbe $ports,
    ) {}

    /** @return list<array{code: string, message: string, hint: string|null}> warnings of the checks */
    public function check(InstanceState $state, string $domain, HostFacts $facts): array
    {
        $context = new DoctorContext($facts, $this->directory, $this->docker, $this->probes, $state, $domain, $state->digests()['app'] ?? null, uuid: $state->uuid());
        $results = [];
        foreach ([new DnsCheck, new PortsCheck($this->ports)] as $check) {
            try {
                $results[] = $check->run($context);
            } catch (DockerUnavailable $e) {
                throw $e;
            } catch (Throwable $e) {
                $results[] = CheckResult::fail($check->key(), $e->getMessage(), ($e instanceof InstanceException ? $e->hint : null) ?? 'Run the command again; if it keeps failing, check the Docker engine.');
            }
        }
        $report = new DoctorReport($results);
        if (! $report->passed()) {
            throw new DoctorFailed($report);
        }

        return array_map(static fn (CheckResult $warning): array => ['code' => 'doctor_'.$warning->key, 'message' => $warning->summary, 'hint' => $warning->hint], $report->warnings());
    }
}
