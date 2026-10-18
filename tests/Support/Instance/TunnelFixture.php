<?php

declare(strict_types=1);

namespace Tests\Support\Instance;

use App\Domain\Instance\Compose\Services\TunnelService;
use App\Domain\Instance\Docker\ContainerSpec;
use App\Domain\Instance\Docker\Labels;
use App\Domain\Instance\Docker\ProcessOutcome;
use App\Domain\Instance\Lifecycle\ComposeFile;
use App\Domain\Instance\State\RuntimeWriter;
use App\Domain\Instance\Tunnel\QuickTunnelHostname;

/**
 * An installed local instance (ConfigFixture) for the `fsa tunnel` and local-CA tests (plan U14, ADR 0082), with
 * scripted answers for what those commands ask:
 *
 * - cloudflared's `/quicktunnel` (`$hostname`, or an empty name while `$hostname` is null);
 * - `app:demo-passwords --check` (`$demoDefaults`, `$otherDefaults`, `$nonDemoOrganizations`) and `--rotate`;
 * - `compose up` starts or removes the tunnel container as the rendered compose file has it (`$tunnelExits` makes it
 *   stop right away, as a cloudflared that cannot reach Cloudflare would).
 */
final class TunnelFixture
{
    public ConfigFixture $config;

    public ?string $hostname = PhoneFixtures::HOSTNAME;

    /** @var list<string> */
    public array $demoDefaults = [];

    /** @var list<string> */
    public array $otherDefaults = [];

    public int $nonDemoOrganizations = 0;

    public bool $tunnelExits = false;

    /** @var list<string> commands of the account one-offs, in order */
    public array $accountCalls = [];

    public InstanceCommandFixture $instance;

    /** @param  array<string, mixed>  $installFlags  e.g. `--phone-https tunnel`: the answers are in place before the install */
    public function __construct(array $installFlags = [])
    {
        $this->config = new ConfigFixture($installFlags, function (InstanceCommandFixture $instance): void {
            $this->instance = $instance;
            $docker = $instance->docker;
            $previousRun = $docker->runResponder;
            $docker->respondToRun(fn (ContainerSpec $spec): ?ProcessOutcome => $this->quickTunnel($spec) ?? ($previousRun instanceof \Closure ? $previousRun($spec) : null));
            $previousOneOff = $docker->composeRunResponder;
            $docker->respondToComposeRun(fn (array $command, ?string $input = null): ProcessOutcome => $this->accounts($command) ?? $previousOneOff($command, $input));
            $docker->onCall['composeUp'] = fn (): null => $this->followCompose();
        });
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array{0: int, 1: array<string, mixed>}
     */
    public function tunnel(string $action, array $options = []): array
    {
        return $this->instance->run('fsa:tunnel', ['action' => $action, ...$options]);
    }

    /** @return array{hostname: string|null, expires_at: string|null, peer: string|null, admin: bool} */
    public function runtimeTunnel(): array
    {
        return new RuntimeWriter($this->instance->directory)->tunnel();
    }

    public function tunnelContainer(): ?string
    {
        foreach ($this->instance->docker->containers as $container) {
            if ($container->service() === TunnelService::NAME) {
                return $container->state;
            }
        }

        return null;
    }

    private function followCompose(): null
    {
        $docker = $this->instance->docker;
        $state = $this->instance->state();
        $rendered = in_array(TunnelService::NAME, ComposeFile::services($this->instance->directory), true);
        $running = $this->tunnelContainer();
        if ($rendered && $running === null) {
            $docker->withRunningContainer('7unne1', '2026-10-18T12:00:00Z', [
                Labels::COMPOSE_PROJECT => $state->project(), Labels::COMPOSE_SERVICE => TunnelService::NAME, Labels::INSTANCE => $state->uuid(),
            ], $state->project().'-tunnel-1');
            if ($this->tunnelExits) {
                $docker->stopContainer('7unne1');
            }
        }
        if (! $rendered && $running !== null) {
            $docker->forgetContainer('7unne1');
        }

        return null;
    }

    private function quickTunnel(ContainerSpec $spec): ?ProcessOutcome
    {
        $url = $spec->command[array_key_last($spec->command)] ?? '';
        if ($spec->entrypoint !== 'curl' || ! str_ends_with($url, QuickTunnelHostname::PATH)) {
            return null;
        }
        if ($this->tunnelExits || $this->tunnelContainer() !== 'running') {
            return new ProcessOutcome(7, '', 'curl: (7) Failed to connect');
        }

        return new ProcessOutcome(0, json_encode(['hostname' => $this->hostname ?? ''], JSON_THROW_ON_ERROR));
    }

    /** @param  list<string>  $command */
    private function accounts(array $command): ?ProcessOutcome
    {
        if (($command[2] ?? null) !== 'app:demo-passwords') {
            return null;
        }
        $this->accountCalls[] = implode(' ', array_slice($command, 2));
        if (in_array('--rotate', $command, true)) {
            $this->demoDefaults = [];

            return DemoFixtures::rotated();
        }
        $users = [...array_map(static fn (string $email): array => ['email' => $email, 'demo' => true], $this->demoDefaults), ...array_map(static fn (string $email): array => ['email' => $email, 'demo' => false], $this->otherDefaults)];

        return InstanceCommandFixture::oneOffJson(['command' => 'app:demo-passwords', 'status' => 'ok', 'data' => [
            'checked' => 12, 'count' => count($users), 'users' => $users,
            'organizations' => ['total' => $this->nonDemoOrganizations + 1, 'non_demo' => $this->nonDemoOrganizations],
        ]]);
    }
}
