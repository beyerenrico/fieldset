<?php

declare(strict_types=1);

namespace App\Domain\Instance\Tunnel;

use App\Domain\Instance\Config\ConfigChange;
use App\Domain\Instance\Config\ConfigFailure;
use App\Domain\Instance\InstanceException;
use App\Domain\Instance\Output\CommandResult;
use App\Domain\Instance\Output\ExitCode;
use App\Domain\Instance\State\InstanceSettings;
use App\Domain\Instance\State\InstanceState;
use Throwable;

/**
 * The envelopes of `fieldset tunnel` (R20, R50, ADR 0082). `data.url` is always the public URL in effect (the tunnel's
 * while it runs, else the instance URL), `data.tunnel` the recorded tunnel (null without one), `data.local_url` the
 * loopback URL that keeps working. The tunnel's warnings say what a person must know: the third party, the internet
 * exposure, the expiry, and that a URL change leaves unsynced phone data behind. A failed start has `config set`'s
 * shape (ADR 0078): exit 5 `rolled_back` with `retry_after` or `fix_first`, exit 8 `revert_failed` with recovery steps,
 * `command` and `options` to repeat, `next_commands`.
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
    public static function started(InstanceState $state, array $tunnel, string $fieldset): CommandResult
    {
        $url = 'https://'.$tunnel['hostname'];
        $result = CommandResult::ok(self::COMMAND, [
            'action' => 'start', 'url' => $url, 'mode' => $state->mode(), 'local_url' => InstanceSettings::of($state)->localUrl(),
            'tunnel' => [...$tunnel, 'url' => $url, 'state' => 'active'],
            'next_commands' => ["{$fieldset} tunnel renew", "{$fieldset} tunnel stop", "{$fieldset} status"],
        ], changed: true)
            ->withWarning('tunnel_third_party', "Traffic to {$url} passes Cloudflare, a third party, and the instance's sign-in, Field App and API are reachable from the internet until the tunnel stops.", 'Quick tunnels are for testing: no uptime guarantee, at most 200 concurrent requests.')
            ->withWarning('tunnel_expires', "The tunnel expires at {$tunnel['expires_at']}; after that its address answers 503.", 'Run `fieldset tunnel renew` to keep it 4 more hours, `fieldset tunnel stop` to end it now.');
        if ($tunnel['admin']) {
            $result = $result->withWarning('tunnel_admin', "The admin panel (/admin) is reachable through {$url} as well (--tunnel-admin).", 'Start the tunnel without --tunnel-admin to keep /admin on this machine.');
        }

        return self::urlChanged($result);
    }

    /** @param  array<string, mixed>  $current  TunnelStatus::of() of a running tunnel */
    public static function running(InstanceState $state, array $current, bool $admin): CommandResult
    {
        $result = CommandResult::ok(self::COMMAND, ['action' => 'start', 'url' => $current['url'], 'mode' => $state->mode(), 'local_url' => InstanceSettings::of($state)->localUrl(), 'tunnel' => $current]);

        return $admin === ($current['admin'] ?? false) ? $result : $result->withWarning('tunnel_running', 'A quick tunnel runs already; --tunnel-admin applies only to a new one.', 'Run `fieldset tunnel stop`, then `fieldset tunnel start` with the options you want.');
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

    /**
     * A start's failure in `config set` terms (ConfigFailure). The tunnel's own hint is the more precise one (no hostname:
     * outbound internet, `fieldset logs tunnel`), except after a signal: that one's says "nothing was changed".
     *
     * @return array{code: string, message: string, hint: string, data: array<string, mixed>}
     */
    public static function failure(Throwable $failure, ConfigChange $change): array
    {
        $error = ConfigFailure::describe($failure, $change);
        if ($error['code'] !== 'interrupted' && $failure instanceof InstanceException && $failure->hint !== null) {
            $error['hint'] = $failure->hint;
        }

        return $error;
    }

    /**
     * The start failed after something changed and the previous stack runs again (exit 5, `rolled_back`).
     *
     * @param  array{code: string, message: string, hint: string, data: array<string, mixed>}  $error
     */
    public static function rolledBack(InstanceState $state, array $error, bool $admin, string $fieldset): CommandResult
    {
        $retry = ConfigFailure::retry($error['code'], $error['hint']);
        $retryable = $retry['retryable'];
        unset($retry['retryable']);

        return CommandResult::failed(self::COMMAND, ExitCode::RolledBack, $error['code'], "Starting the quick tunnel failed: {$error['message']} The instance runs as before, without a tunnel.", $error['hint'], $retryable, [
            ...self::local($state, 'start'), 'reverted' => true, 'failure' => $error['data'], ...$retry,
            'command' => 'fieldset tunnel start'.($admin ? ' --tunnel-admin' : ''), 'options' => $admin ? ['--tunnel-admin' => true] : [],
            'next_commands' => ["{$fieldset} status"],
        ]);
    }

    /**
     * Restoring the previous stack failed too (exit 8). The journal stays open; `fieldset tunnel stop` finishes it. The
     * recovery text follows the revert steps that completed (`files`: state.json and the rendered files are the ones
     * without the tunnel again).
     *
     * @param  array{code: string, message: string, hint: string, data: array<string, mixed>}  $error
     * @param  array{code: string, message: string, hint: string, data: array<string, mixed>}  $revertError
     * @param  list<string>  $steps  completed revert steps (`files`, `services`)
     * @param  list<string>|null  $stopped  services stopped afterwards, null when stopping failed
     */
    public static function revertFailed(InstanceState $state, array $error, array $revertError, array $steps, ?array $stopped): CommandResult
    {
        $where = in_array('files', $steps, true)
            ? 'The settings without the tunnel are back in state.json and the files rendered from it, but their services did not come up'
            : 'Writing the settings without the tunnel back failed: state.json and the files rendered from it may still have the tunnel';
        $recovery = [
            $where.($stopped !== null ? '; '.implode(', ', $stopped).' are stopped, so nothing serves requests half-configured (Postgres and Valkey keep running).' : '; stopping the services failed as well, check `fieldset status`.'),
            'Find the cause in the errors above and in `fieldset logs <service>` (a port taken by another program, a full disk, the Docker engine) and fix it.',
            'Then run `fieldset tunnel stop`: it removes the tunnel, brings the whole stack up on the settings without it and closes the unfinished operation.',
        ];

        return CommandResult::failed(self::COMMAND, ExitCode::RollbackFailed, 'revert_failed', "Starting the quick tunnel failed ({$error['message']}) and restoring the previous stack failed too: {$revertError['message']}", 'Follow the recovery steps in data.recovery.', false, [
            ...self::local($state, 'start'), 'reverted' => false, 'stopped' => $stopped !== null, 'stopped_services' => $stopped ?? [], 'revert_steps' => $steps,
            'failure' => [...$error['data'], 'code' => $error['code']], 'revert_failure' => [...$revertError['data'], 'code' => $revertError['code']],
            'recovery' => $recovery, 'command' => 'fieldset tunnel stop', 'options' => [],
        ], changed: true);
    }

    /** @return array{action: string, url: string, mode: string, local_url: string|null, tunnel: null} */
    private static function local(InstanceState $state, string $action): array
    {
        return ['action' => $action, 'url' => InstanceSettings::of($state)->instanceUrl(), 'mode' => $state->mode(), 'local_url' => InstanceSettings::of($state)->localUrl(), 'tunnel' => null];
    }

    private static function urlChanged(CommandResult $result): CommandResult
    {
        return $result->withWarning('url_changed_phone_data', 'The instance URL changed: data a phone has not synced yet stays behind with the old address, and home-screen apps and links already sent keep the old address.', 'Let every phone sync before you start, stop or renew a tunnel.');
    }
}
