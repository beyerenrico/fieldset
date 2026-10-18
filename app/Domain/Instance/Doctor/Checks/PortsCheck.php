<?php

declare(strict_types=1);

namespace App\Domain\Instance\Doctor\Checks;

use App\Domain\Instance\Compose\Services\AppRoles;
use App\Domain\Instance\Compose\Services\MailpitService;
use App\Domain\Instance\Docker\ComposeProject;
use App\Domain\Instance\Docker\ContainerInfo;
use App\Domain\Instance\Docker\DockerCommandFailed;
use App\Domain\Instance\Doctor\CheckResult;
use App\Domain\Instance\Doctor\DoctorCheck;
use App\Domain\Instance\Doctor\DoctorContext;
use App\Domain\Instance\Doctor\PortProbe;
use App\Domain\Instance\State\InstanceSettings;
use App\Domain\Instance\State\InstanceState;

/**
 * Loopback ports (R10, R15, AE4). For `fsa install` it selects them: the app gets 8080 or the next free port
 * (an explicit --port must be free), the mail catcher UI 8025 or the next free one; the choice lands in the
 * install journal and the state, so a resumed or repeated install keeps its own ports without probing again. An
 * install on a shared proxy network publishes no app port, so only the catcher UI's is chosen; an install with
 * --smtp-host runs no catcher, so no catcher UI port is chosen (ADR 0080 amendment).
 * For `fsa doctor` it checks the instance's ports: a port published by the instance's own running container
 * (app, mailpit) is served by it and not probed.
 *
 * Local-CA mode (ADR 0082) adds 80 and 443 on the LAN address (LocalCaPorts).
 *
 * `details` has one shape in both modes: `ports` (`app`, `mail_ui` => port; empty when none was found) and
 * `taken` (ports in use by something else).
 */
final readonly class PortsCheck implements DoctorCheck
{
    /** How many ports above the default are tried. */
    public const int SEARCH = 20;

    /** Port key => the compose service that publishes it. */
    private const array PUBLISHED_BY = ['app' => AppRoles::APP, 'mail_ui' => MailpitService::NAME];

    public function __construct(private PortProbe $probe) {}

    public function key(): string
    {
        return 'ports';
    }

    public function run(DoctorContext $context): CheckResult
    {
        $image = $context->appImage;
        if ($image === null) {
            return CheckResult::skipped($this->key(), 'No app image on this host to probe ports with yet.');
        }
        if ($context->domain !== null) {
            return new EdgePorts($this->probe)->check($context, $image, $this->key()); // domain mode: 80 and 443 (U8)
        }

        $loopback = $context->selectPorts ? $this->select($context, $image) : $this->verify($context, $image);

        return $context->lanAddress === null ? $loopback : new LocalCaPorts($this->probe)->check($context, $image, $loopback);
    }

    private function select(DoctorContext $context, string $image): CheckResult
    {
        if (! $context->publishesAppPort) {
            return $this->selectCatcherOnly($context, $image);
        }
        $taken = [];
        $requested = $context->requestedPort;
        if ($requested !== null && ! $this->probe->isFree($requested, $image, $context->uuid)) {
            return CheckResult::fail($this->key(), "Port {$requested} is in use on 127.0.0.1.", 'Free the port, or pass another --port (without --port fsa picks 8080 or the next free port).', ['ports' => [], 'taken' => [$requested]]);
        }
        $port = $requested ?? $this->firstFree(InstanceSettings::DEFAULT_PORT, [], $image, $context->uuid, $taken);
        $mail = $port === null || ! $context->catcher ? null : $this->firstFree(InstanceSettings::DEFAULT_MAIL_UI_PORT, [$port], $image, $context->uuid, $taken);
        if ($port === null || ($context->catcher && $mail === null)) {
            return CheckResult::fail($this->key(), 'No free loopback port found (tried '.implode(', ', $taken).').', 'Free one of these ports, or pass --port with a free one.', ['ports' => [], 'taken' => $taken]);
        }
        $summary = "App on 127.0.0.1:{$port}".($mail === null ? '' : ", mail catcher UI on 127.0.0.1:{$mail}").($taken === [] ? '.' : ' (in use: '.implode(', ', $taken).').');

        return CheckResult::ok($this->key(), $summary, ['ports' => ['app' => $port, ...($mail === null ? [] : ['mail_ui' => $mail])], 'taken' => $taken]);
    }

    /**
     * On a shared proxy network the app publishes nothing (ADR 0076), so only the catcher UI needs a loopback port
     * (ADR 0078: a taken 8080–8099 must not stop a Traefik or caddy-docker-proxy install).
     */
    private function selectCatcherOnly(DoctorContext $context, string $image): CheckResult
    {
        if (! $context->catcher) {
            return CheckResult::ok($this->key(), 'The app publishes no port on the proxy network and no mail catcher runs (SMTP).', ['ports' => [], 'taken' => []]);
        }
        $taken = [];
        $mail = $this->firstFree(InstanceSettings::DEFAULT_MAIL_UI_PORT, [], $image, $context->uuid, $taken);
        if ($mail === null) {
            return CheckResult::fail($this->key(), 'No free loopback port for the mail catcher UI found (tried '.implode(', ', $taken).').', 'Free one of these ports.', ['ports' => [], 'taken' => $taken]);
        }

        return CheckResult::ok($this->key(), "Mail catcher UI on 127.0.0.1:{$mail}; the app publishes no port on the proxy network.", ['ports' => ['mail_ui' => $mail], 'taken' => $taken]);
    }

    /**
     * @param  list<int>  $exclude
     * @param  list<int>  $taken
     */
    private function firstFree(int $start, array $exclude, string $image, ?string $uuid, array &$taken): ?int
    {
        for ($port = $start; $port < $start + self::SEARCH; $port++) {
            if (in_array($port, $exclude, true)) {
                continue;
            }
            if ($this->probe->isFree($port, $image, $uuid)) {
                return $port;
            }
            $taken[] = $port;
        }

        return null;
    }

    private function verify(DoctorContext $context, string $image): CheckResult
    {
        $ports = $this->instancePorts($context->state);
        $running = $this->runningServices($context);
        $served = array_filter($ports, static fn (int $port, string $key): bool => in_array(self::PUBLISHED_BY[$key], $running, true), ARRAY_FILTER_USE_BOTH);
        $probed = array_diff_key($ports, $served);
        $taken = array_values(array_filter($probed, fn (int $port): bool => ! $this->probe->isFree($port, $image, $context->uuid)));
        $details = ['ports' => $ports, 'taken' => $taken];
        if ($taken !== [] && ! $context->installed()) {
            return CheckResult::warn($this->key(), 'Port(s) '.implode(', ', $taken).' in use on 127.0.0.1.', 'fsa install picks the next free port and reports it.', $details);
        }
        if ($taken !== []) {
            return CheckResult::fail($this->key(), 'Port(s) '.implode(', ', $taken).' of this instance are in use by another program.', 'Stop the other program, then start the instance again.', $details);
        }
        $summary = match (true) {
            $probed === [] => 'Ports '.implode(', ', $ports).' are served by this instance.',
            $served === [] => 'Ports '.implode(', ', $ports).' are free.',
            default => 'Port(s) '.implode(', ', $served).' served by this instance, '.implode(', ', $probed).' free.',
        };

        return CheckResult::ok($this->key(), $summary, $details);
    }

    /** @return array<string, int> */
    private function instancePorts(?InstanceState $state): array
    {
        if (! $state instanceof InstanceState) {
            return ['app' => InstanceSettings::DEFAULT_PORT, 'mail_ui' => InstanceSettings::DEFAULT_MAIL_UI_PORT];
        }
        $settings = InstanceSettings::of($state);

        // On a shared proxy network the app publishes no port (ADR 0076), so there is nothing to probe for it.
        return [...($settings->publishesAppPort() ? ['app' => $settings->port()] : []), ...($settings->runsCatcher() ? ['mail_ui' => $settings->mailUiPort()] : [])];
    }

    /** @return list<string> compose services of this instance whose container runs */
    private function runningServices(DoctorContext $context): array
    {
        if (! $context->state instanceof InstanceState) {
            return [];
        }

        try {
            $containers = $context->docker->composePs(ComposeProject::forInstance($context->state, $context->directory));
        } catch (DockerCommandFailed) {
            return [];
        }
        $running = array_filter($containers, static fn (ContainerInfo $container): bool => $container->running() && $container->service() !== null);

        return array_values(array_map(static fn (ContainerInfo $container): string => (string) $container->service(), $running));
    }
}
