<?php

declare(strict_types=1);

namespace App\Domain\Instance\Adopt;

use App\Domain\Instance\Docker\DockerGateway;
use App\Domain\Instance\HostFacts;
use App\Domain\Instance\Install\InstallPreflight;
use App\Domain\Instance\InstanceException;

/**
 * Adopt's `doctor` phase (plan U19, ADR 0087): nothing changes and 1.0 keeps running. It finds the 1.0 install
 * (LegacyInstall: refusals exit 7), runs install's preflight for the mode AdoptSettings infers (release verified, doctor,
 * ports, project conflicts: exit 3/7/10), checks for twice the 1.0 data's space (exit 3), and returns install's phase data
 * with the 1.0 port (held by 1.0's app until adopt stops it, so the doctor probed another one), plus what later phases
 * need: `legacy` (the containers and volumes), `settings`, `pending_mail` and `space`.
 */
final readonly class AdoptPreflight
{
    public function __construct(private InstallPreflight $preflight, private DockerGateway $docker, private AdoptSpace $space) {}

    /**
     * @return array<string, mixed>
     *
     * @throws InstanceException
     */
    public function run(LegacyCheckout $checkout, ?string $release, HostFacts $facts): array
    {
        $legacy = LegacyInstall::discover($this->docker, $checkout);
        $request = AdoptSettings::request($checkout, $release, in_array('app', $legacy->running, true));
        $request->assertConsistent();
        $data = $this->preflight->run($request, $facts);
        /** @var array<string, string> $digests */
        $digests = $data['digests'];
        $space = $this->space->assert($legacy, $digests['app'], (string) $data['uuid']);

        return [...$data, 'port' => $checkout->port(), 'request' => $request->toContext(), 'legacy' => $legacy->toArray(),
            'settings' => AdoptSettings::overlay($checkout), 'pending_mail' => AdoptSettings::pendingMail($checkout), 'space' => $space];
    }
}
