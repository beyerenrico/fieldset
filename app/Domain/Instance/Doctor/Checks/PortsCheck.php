<?php

declare(strict_types=1);

namespace App\Domain\Instance\Doctor\Checks;

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
 * install journal and the state, so a resumed or repeated install keeps its own ports without probing again.
 * For `fsa doctor` it checks the instance's ports: in use by its own running app is fine.
 */
final readonly class PortsCheck implements DoctorCheck
{
    /** How many ports above the default are tried. */
    public const int SEARCH = 20;

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

        return $context->selectPorts ? $this->select($context, $image) : $this->verify($context, $image);
    }

    private function select(DoctorContext $context, string $image): CheckResult
    {
        $taken = [];
        $requested = $context->requestedPort;
        if ($requested !== null && ! $this->probe->isFree($requested, $image, $context->uuid)) {
            return CheckResult::fail($this->key(), "Port {$requested} is in use on 127.0.0.1.", 'Free the port, or pass another --port (without --port fsa picks 8080 or the next free port).', ['taken' => [$requested]]);
        }
        $port = $requested ?? $this->firstFree(InstanceSettings::DEFAULT_PORT, [], $image, $context->uuid, $taken);
        $mail = $port === null ? null : $this->firstFree(InstanceSettings::DEFAULT_MAIL_UI_PORT, [$port], $image, $context->uuid, $taken);
        if ($port === null || $mail === null) {
            return CheckResult::fail($this->key(), 'No free loopback port found (tried '.implode(', ', $taken).').', 'Free one of these ports, or pass --port with a free one.', ['taken' => $taken]);
        }
        $summary = "App on 127.0.0.1:{$port}, mail catcher UI on 127.0.0.1:{$mail}".($taken === [] ? '.' : ' (in use: '.implode(', ', $taken).').');

        return CheckResult::ok($this->key(), $summary, ['port' => $port, 'mail_ui_port' => $mail, 'taken' => $taken]);
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
        $list = implode(', ', $ports);
        if ($context->installed() && $this->appRunning($context)) {
            return CheckResult::ok($this->key(), "Ports {$list} are served by this instance.", ['ports' => $ports]);
        }
        $taken = array_values(array_filter($ports, fn (int $port): bool => ! $this->probe->isFree($port, $image, $context->uuid)));
        $details = ['ports' => $ports, 'taken' => $taken];
        if ($taken === []) {
            return CheckResult::ok($this->key(), "Ports {$list} are free.", $details);
        }
        if (! $context->installed()) {
            return CheckResult::warn($this->key(), 'Port(s) '.implode(', ', $taken).' in use on 127.0.0.1.', 'fsa install picks the next free port and reports it.', $details);
        }

        return CheckResult::fail($this->key(), 'Port(s) '.implode(', ', $taken).' of this instance are in use by another program.', 'Stop the other program, then start the instance again.', $details);
    }

    /** @return array<string, int> */
    private function instancePorts(?InstanceState $state): array
    {
        if (! $state instanceof InstanceState) {
            return ['app' => InstanceSettings::DEFAULT_PORT, 'mail_ui' => InstanceSettings::DEFAULT_MAIL_UI_PORT];
        }
        $settings = InstanceSettings::of($state);

        return ['app' => $settings->port(), ...($settings->capturesMail() ? ['mail_ui' => $settings->mailUiPort()] : [])];
    }

    private function appRunning(DoctorContext $context): bool
    {
        if (! $context->state instanceof InstanceState) {
            return false;
        }

        try {
            $containers = $context->docker->composePs(ComposeProject::forInstance($context->state, $context->directory));
        } catch (DockerCommandFailed) {
            return false;
        }

        return array_any($containers, static fn (ContainerInfo $container): bool => $container->service() === 'app' && $container->running());
    }
}
