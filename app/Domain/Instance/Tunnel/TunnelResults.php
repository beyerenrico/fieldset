<?php

declare(strict_types=1);

namespace App\Domain\Instance\Tunnel;

use App\Domain\Instance\Output\CommandResult;
use App\Domain\Instance\State\InstanceSettings;
use App\Domain\Instance\State\InstanceState;

/**
 * The envelopes of `fsa tunnel` (R20, R50, ADR 0082). `data.url` is always the public URL in effect (the tunnel's
 * while it runs, else the instance URL), `data.tunnel` the recorded tunnel (null without one), `data.local_url` the
 * loopback URL that keeps working. The tunnel's warnings say what a person must know: the third party, the internet
 * exposure, the expiry, and that a URL change leaves unsynced phone data behind.
 */
final class TunnelResults
{
    public const string COMMAND = 'tunnel';

    /**
     * The tunnel as a journaled phase returned it.
     *
     * @param  array<string, mixed>  $data
     * @return array{hostname: string, expires_at: string, peer: string, admin: bool}
     */
    public static function tunnel(array $data): array
    {
        $string = static fn (string $key): string => is_string($data[$key] ?? null) ? $data[$key] : '';

        return ['hostname' => $string('hostname'), 'expires_at' => $string('expires_at'), 'peer' => $string('peer'), 'admin' => ($data['admin'] ?? false) === true];
    }

    /** @param  array{hostname: string, expires_at: string, peer: string, admin: bool}  $tunnel */
    public static function started(InstanceState $state, array $tunnel, string $fsa): CommandResult
    {
        $url = 'https://'.$tunnel['hostname'];
        $result = CommandResult::ok(self::COMMAND, [
            'action' => 'start', 'url' => $url, 'mode' => $state->mode(), 'local_url' => InstanceSettings::of($state)->localUrl(),
            'tunnel' => [...$tunnel, 'url' => $url, 'state' => 'active'],
            'next_commands' => ["{$fsa} tunnel renew", "{$fsa} tunnel stop", "{$fsa} status"],
        ], changed: true)
            ->withWarning('tunnel_third_party', "Traffic to {$url} passes Cloudflare, a third party, and the instance's sign-in, Field App and API are reachable from the internet until the tunnel stops.", 'Quick tunnels are for testing: no uptime guarantee, at most 200 concurrent requests.')
            ->withWarning('tunnel_expires', "The tunnel expires at {$tunnel['expires_at']}; after that its address answers 503.", 'Run `fsa tunnel renew` to keep it 4 more hours, `fsa tunnel stop` to end it now.');
        if ($tunnel['admin']) {
            $result = $result->withWarning('tunnel_admin', "The admin panel (/admin) is reachable through {$url} as well (--tunnel-admin).", 'Start the tunnel without --tunnel-admin to keep /admin on this machine.');
        }

        return self::urlChanged($result);
    }

    /** @param  array<string, mixed>  $current  TunnelStatus::of() of a running tunnel */
    public static function running(InstanceState $state, array $current, bool $admin): CommandResult
    {
        $result = CommandResult::ok(self::COMMAND, ['action' => 'start', 'url' => $current['url'], 'mode' => $state->mode(), 'local_url' => InstanceSettings::of($state)->localUrl(), 'tunnel' => $current]);

        return $admin === ($current['admin'] ?? false) ? $result : $result->withWarning('tunnel_running', 'A quick tunnel runs already; --tunnel-admin applies only to a new one.', 'Run `fsa tunnel stop`, then `fsa tunnel start` with the options you want.');
    }

    /**
     * @param  array{hostname: string, expires_at: string, peer: string, admin: bool}  $tunnel
     * @param  array<string, mixed>  $previous  TunnelStatus::of() before
     */
    public static function renewed(InstanceState $state, array $tunnel, array $previous): CommandResult
    {
        $url = 'https://'.$tunnel['hostname'];
        $result = CommandResult::ok(self::COMMAND, [
            'action' => 'renew', 'url' => $url, 'mode' => $state->mode(), 'local_url' => InstanceSettings::of($state)->localUrl(),
            'tunnel' => [...$tunnel, 'url' => $url, 'state' => 'active'], 'previous_expires_at' => $previous['expires_at'] ?? null,
        ], changed: true)->withWarning('tunnel_expires', "The tunnel now expires at {$tunnel['expires_at']}.");

        return $tunnel['hostname'] === ($previous['hostname'] ?? null) ? $result : self::urlChanged($result->withWarning('tunnel_hostname_changed', "cloudflared reported a new address, {$url}; the old one no longer works."));
    }

    public static function notRunning(InstanceState $state): CommandResult
    {
        return CommandResult::ok(self::COMMAND, ['action' => 'stop', 'url' => InstanceSettings::of($state)->instanceUrl(), 'mode' => $state->mode(), 'local_url' => InstanceSettings::of($state)->localUrl(), 'tunnel' => null]);
    }

    /**
     * @param  array<string, mixed>  $previous  TunnelStatus::of() before
     * @param  array<string, mixed>  $services
     */
    public static function stopped(InstanceState $state, array $previous, array $services): CommandResult
    {
        return self::urlChanged(CommandResult::ok(self::COMMAND, [
            'action' => 'stop', 'url' => InstanceSettings::of($state)->instanceUrl(), 'mode' => $state->mode(), 'local_url' => InstanceSettings::of($state)->localUrl(),
            'tunnel' => null, 'previous' => ['hostname' => $previous['hostname'] ?? null, 'expires_at' => $previous['expires_at'] ?? null, 'state' => $previous['state'] ?? null],
            'services' => $services,
        ], changed: true));
    }

    private static function urlChanged(CommandResult $result): CommandResult
    {
        return $result->withWarning('url_changed_phone_data', 'The instance URL changed: data a phone has not synced yet stays behind with the old address, and home-screen apps and links already sent keep the old address.', 'Let every phone sync before you start, stop or renew a tunnel.');
    }
}
