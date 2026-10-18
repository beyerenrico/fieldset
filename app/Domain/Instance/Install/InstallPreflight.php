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

    /** Domain and local-CA mode add the edge (U8, U14). */
    private const array DOMAIN_ROLES = [ImageRole::Caddy];

    public function __construct(
        private InstanceDirectory $directory,
        private DockerGateway $docker,
        private Doctor $doctor,
        private DoctorProbes $probes,
        private ReleaseSource $release,
        private ProjectConflicts $conflicts,
        private ProxySetup $proxy,
    ) {}

    /** @return array<string, mixed> uuid, project, version, digests, port, mail_ui_port, emulated, warnings, orphans, mode, url, proxy, edge, phone, mail */
    public function run(InstallRequest $request, HostFacts $facts): array
    {
        $state = $this->directory->hasState() ? $this->directory->state() : null;
        $manifest = $this->release->verified($this->directory, $facts, $request->version());
        $digests = ReleaseSource::digests($manifest, $facts->registry);
        $uuid = $state?->uuid() ?? Str::uuid()->toString();
        $project = $state?->project() ?? InstanceState::projectFor(InstanceState::slugFor($this->directory->root), $uuid);
        $localCa = InstallMode::localCa($request, $state, $uuid, $facts, $this->docker->networkSubnets(...));
        $mail = InstallMail::settings($request->explicit()); // --smtp-host: SMTP mode, no catcher (ADR 0080 amendment)
        $report = $this->doctor->run(new DoctorContext(
            $facts, $this->directory, $this->docker, $this->probes, $state, $request->domain(), $digests['app'] ?? null,
            images: $this->images($digests, $request->domain() !== null || $localCa !== null, $mail === null), platforms: $manifest->platforms,
            requestedPort: $request->port(), selectPorts: true, uuid: $uuid,
            publishesAppPort: ! in_array($request->proxy(), InstallRequest::NETWORK_PROXIES, true),
            lanAddress: $localCa['lan_address'] ?? null, catcher: $mail === null,
        ));
        if (! $report->passed()) {
            throw new DoctorFailed($report);
        }
        $this->conflicts->assertFree($project, $uuid);
        $proxy = $this->proxy->resolve($request);
        $ports = $this->ports($report, $request->domain() !== null, in_array($request->proxy(), InstallRequest::NETWORK_PROXIES, true), $mail === null);
        $edge = InstallMode::edge($request, $state, $uuid, $this->docker->networkSubnets(...));

        return [
            'uuid' => $uuid,
            'project' => $project,
            'version' => (string) $manifest->version,
            'digests' => $digests,
            'port' => $ports['app'] ?? null,
            'mail_ui_port' => $ports['mail_ui'] ?? null,
            // DOCKER_DEFAULT_PLATFORM selects another architecture than the engine's: the renderer doubles memory limits.
            'emulated' => ($report->result('architecture')?->details['emulated'] ?? false) === true,
            'warnings' => $this->warnings($report, $request),
            'orphans' => $this->conflicts->orphans($this->directory->root, $uuid),
            // Proxy mode (ADR 0076): the URL from --url and the single address the app will trust. Domain mode (ADR 0075):
            // https://<domain>, the ACME inputs and the edge subnet.
            'mode' => InstallMode::mode($request, $proxy),
            'url' => match (true) {
                $edge !== null => 'https://'.$edge['domain'],
                $localCa !== null => 'https://'.$localCa['lan_address'],
                default => $request->url(),
            },
            'proxy' => $proxy,
            'edge' => $edge,
            // Local-CA mode (ADR 0082): the LAN address, the edge subnet; the URL is https://<address>.
            'phone' => $localCa,
            // SMTP mode (--smtp-host, ADR 0080 amendment): the mail settings for the state; null captures mail.
            'mail' => $mail,
        ];
    }

    /**
     * The images the instance runs, for the registry check: the edge's only with one, the catcher's only in capture mode.
     *
     * @param  array<string, string>  $digests  image role => reference
     * @return list<string>
     */
    private function images(array $digests, bool $edge, bool $catcher): array
    {
        $roles = array_filter([...self::LOCAL_ROLES, ...($edge ? self::DOMAIN_ROLES : [])], static fn (ImageRole $role): bool => $catcher || $role !== ImageRole::Mailpit);

        return array_values(array_intersect_key($digests, array_flip(array_map(static fn (ImageRole $role): string => $role->value, $roles))));
    }

    /** @return list<array{code: string, message: string, hint: string|null}> the doctor's warnings and the install's own */
    private function warnings(DoctorReport $report, InstallRequest $request): array
    {
        return [
            ...array_map(static fn (CheckResult $warning): array => ['code' => $warning->warningCode(), 'message' => $warning->summary, 'hint' => $warning->hint], $report->warnings()),
            ...InstallMail::warnings($request->explicit()),
        ];
    }

    /**
     * The loopback ports the doctor chose (local mode, the proxy `port` setup); a shared proxy network publishes only
     * the catcher UI's, domain mode only the edge's 80 and 443, and SMTP mode no catcher UI port.
     *
     * @return array{app?: int, mail_ui?: int}
     */
    private function ports(DoctorReport $report, bool $domain, bool $sharedNetwork, bool $catcher): array
    {
        if ($domain) {
            return [];
        }
        $details = $report->result('ports')->details ?? [];
        $ports = is_array($details['ports'] ?? null) ? $details['ports'] : [];
        if ((! $sharedNetwork && ! is_int($ports['app'] ?? null)) || ($catcher && ! is_int($ports['mail_ui'] ?? null))) {
            throw new InstanceException('The preflight chose no ports.', ExitCode::PreflightFailed, 'ports_unknown', 'Run `fsa doctor` to see why the port check did not run.');
        }

        return array_filter(['app' => $sharedNetwork ? null : $ports['app'], 'mail_ui' => $catcher ? $ports['mail_ui'] : null], is_int(...));
    }
}
