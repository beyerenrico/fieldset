<?php

declare(strict_types=1);

namespace App\Domain\Instance\Install;

use App\Domain\Instance\Compose\ImageRole;
use App\Domain\Instance\Docker\DockerGateway;
use App\Domain\Instance\Doctor\CheckResult;
use App\Domain\Instance\Doctor\Doctor;
use App\Domain\Instance\Doctor\DoctorContext;
use App\Domain\Instance\Doctor\DoctorFailed;
use App\Domain\Instance\Doctor\DoctorProbes;
use App\Domain\Instance\Doctor\DoctorReport;
use App\Domain\Instance\HostFacts;
use App\Domain\Instance\InstanceException;
use App\Domain\Instance\Output\ExitCode;
use App\Domain\Instance\State\InstanceDirectory;
use App\Domain\Instance\State\InstanceState;
use Illuminate\Support\Str;

/**
 * Install's `doctor` phase (F2, R10, AE5): verify the release, run the doctor (which also chooses the ports), and
 * refuse a compose project that belongs to someone else. Nothing is written and nothing but the cosign image (if
 * absent) is pulled, so a failure here is exit 3/7/10 with nothing changed. Its result is the phase data every
 * later phase and a resumed run build on.
 */
final readonly class InstallPreflight
{
    /** Images local mode with capture mail runs. */
    private const array LOCAL_ROLES = [ImageRole::App, ImageRole::Postgres, ImageRole::Valkey, ImageRole::Gotenberg, ImageRole::Mailpit];

    /** Domain mode adds the edge (U8). */
    private const array DOMAIN_ROLES = [ImageRole::Caddy];

    public function __construct(
        private InstanceDirectory $directory,
        private DockerGateway $docker,
        private Doctor $doctor,
        private DoctorProbes $probes,
        private ReleaseSource $release,
        private ProjectConflicts $conflicts,
    ) {}

    /** @return array<string, mixed> uuid, project, version, digests, port, mail_ui_port, mode, emulated, warnings, orphans */
    public function run(InstallRequest $request, HostFacts $facts): array
    {
        $state = $this->directory->hasState() ? $this->directory->state() : null;
        $manifest = $this->release->verified($this->directory, $facts, $request->version());
        $digests = ReleaseSource::digests($manifest, $facts->registry);
        $uuid = $state?->uuid() ?? Str::uuid()->toString();
        $project = $state?->project() ?? InstanceState::projectFor(InstanceState::slugFor($this->directory->root), $uuid);
        $roles = array_map(static fn (ImageRole $role): string => $role->value, [...self::LOCAL_ROLES, ...($request->domain() === null ? [] : self::DOMAIN_ROLES)]);
        $report = $this->doctor->run(new DoctorContext(
            $facts, $this->directory, $this->docker, $this->probes, $state, $request->domain(), $digests['app'] ?? null,
            images: array_values(array_intersect_key($digests, array_flip($roles))), platforms: $manifest->platforms,
            requestedPort: $request->port(), selectPorts: true, uuid: $uuid,
        ));
        if (! $report->passed()) {
            throw new DoctorFailed($report);
        }
        $this->conflicts->assertFree($project, $uuid);
        $ports = $this->ports($report, $request->domain() !== null);
        $mode = InstallMode::forRequest($request, $state, $uuid, $this->docker->networkSubnets(...));

        return [
            'uuid' => $uuid,
            'project' => $project,
            'version' => (string) $manifest->version,
            'digests' => $digests,
            'port' => $ports['app'] ?? null,
            'mail_ui_port' => $ports['mail_ui'] ?? null,
            'mode' => $mode,
            // DOCKER_DEFAULT_PLATFORM selects another architecture than the engine's: the renderer doubles memory limits.
            'emulated' => ($report->result('architecture')?->details['emulated'] ?? false) === true,
            'warnings' => array_map(static fn (CheckResult $warning): array => ['code' => 'doctor_'.$warning->key, 'message' => $warning->summary, 'hint' => $warning->hint], $report->warnings()),
            'orphans' => $this->conflicts->orphans($this->directory->root, $uuid),
        ];
    }

    /**
     * The loopback ports the doctor chose (local mode); domain mode publishes only the edge's 80 and 443.
     *
     * @return array{app?: int, mail_ui?: int}
     */
    private function ports(DoctorReport $report, bool $domain): array
    {
        $details = $report->result('ports')->details ?? [];
        $ports = is_array($details['ports'] ?? null) ? $details['ports'] : [];
        if ($domain) {
            return [];
        }
        if (! is_int($ports['app'] ?? null) || ! is_int($ports['mail_ui'] ?? null)) {
            throw new InstanceException('The preflight chose no ports.', ExitCode::PreflightFailed, 'ports_unknown', 'Run `fsa doctor` to see why the port check did not run.');
        }

        return ['app' => $ports['app'], 'mail_ui' => $ports['mail_ui']];
    }
}
