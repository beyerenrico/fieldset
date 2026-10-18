<?php

declare(strict_types=1);

namespace App\Domain\Instance\Config\Appliers;

use App\Domain\Instance\Doctor\Checks\PortsCheck;
use App\Domain\Instance\Doctor\PortProbe;
use App\Domain\Instance\InstanceException;
use App\Domain\Instance\Output\ExitCode;
use App\Domain\Instance\State\InstanceSettings;
use App\Domain\Instance\State\InstanceState;

/**
 * Loopback ports for `config set port` and `config set mode local` (R15, AE4, ADR 0072, ADR 0077): a port this
 * instance already publishes is its own; any other is probed through the engine like the install's ports check,
 * and the search for a free one walks the same range (PortsCheck::SEARCH ports from the default on, the default
 * included).
 */
final readonly class LoopbackPorts
{
    public function __construct(private PortProbe $probe) {}

    /** Whether the instance (in its current state) publishes this port for the app or the catcher UI. */
    public static function published(InstanceState $state, int $port): bool
    {
        $settings = InstanceSettings::of($state);
        $app = $settings->publishesAppPort() && $settings->port() === $port;
        $catcher = $settings->runsCatcher() && $state->mode() !== 'domain' && $settings->mailUiPort() === $port;

        return $app || $catcher;
    }

    public function isFree(InstanceState $state, int $port): bool
    {
        return self::published($state, $port) || $this->probe->isFree($port, $state->digests()['app'], $state->uuid());
    }

    /**
     * The preferred port if it is free (or already this instance's), else the first free one after the default.
     *
     * @param  list<int>  $exclude  ports already chosen for something else
     *
     * @throws InstanceException exit 3 when the whole range is taken
     */
    public function choose(InstanceState $state, int $preferred, int $default, array $exclude): int
    {
        $candidates = array_unique([$preferred, ...range($default, $default + PortsCheck::SEARCH - 1)]);
        $taken = [];
        foreach ($candidates as $port) {
            if (in_array($port, $exclude, true)) {
                continue;
            }
            if ($this->isFree($state, $port)) {
                return $port;
            }
            $taken[] = $port;
        }

        throw new InstanceException('No free loopback port found (tried '.implode(', ', $taken).').', ExitCode::PreflightFailed, 'port_in_use', 'Free one of these ports, then run the command again.', false, ['taken' => $taken]);
    }
}
