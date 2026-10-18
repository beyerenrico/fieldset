<?php

declare(strict_types=1);

namespace App\Domain\Instance\Install;

use App\Domain\Instance\Config\ConfigKeys;
use App\Domain\Instance\State\InstanceState;

/**
 * What a repeated `fsa install` with different flags tells an agent (exit 2 `use_config_set`, ADR 0078): per flag the
 * `fsa config set` key that changes it on this instance, or none, and a hint that says plainly what is possible.
 * Proxy mode cannot be entered or re-wired on an installed instance, and until `--from-backup` (2.0.0-rc.1) a fresh
 * install starts without the old data; there is no backup command yet, only uninstall's final backup.
 */
final class InstallFlags
{
    public const string PROXY_SWITCH = 'Switching to proxy mode, or changing its setup or network, is not possible on an installed instance: it needs `fsa uninstall` and a fresh `fsa install --behind-proxy traefik|caddy|port --url <origin>`. The data cannot be carried over until 2.0.0-rc.1 (`fsa install --from-backup`); `fsa uninstall --keep-backups` first writes a final backup (database dump, files, APP_KEY) into backups/ for that.';

    /** Install flag => the config key for it. */
    private const array KEYS = [
        '--domain' => 'domain', '--url' => 'url', '--port' => 'port', '--trusted-proxy' => 'trusted-proxy',
        '--acme-email' => 'acme-email', '--acme-ca' => 'acme-ca', '--acme-ca-root-file' => 'acme-ca',
        '--phone-https' => 'phone-https', '--lan-address' => 'lan-address',
        '--smtp-host' => 'mail', '--smtp-port' => 'mail', '--smtp-encryption' => 'mail', '--smtp-username' => 'mail',
        '--from-address' => 'mail', '--from-name' => 'mail',
    ];

    private const array PROXY = ['--behind-proxy', '--proxy-network'];

    private const array APP = ['--org', '--owner-email', '--owner-name', '--locale'];

    /**
     * @param  list<string>  $flags  the flags that differ from the installation
     * @return list<array{flag: string, key: string|null}>
     */
    public static function keys(array $flags, InstanceState $state): array
    {
        $switch = self::proxySwitch($flags, $state);

        return array_map(static function (string $flag) use ($state, $switch): array {
            $key = self::KEYS[$flag] ?? null;
            if ($key === null || ($flag === '--url' && $switch)) {
                return ['flag' => $flag, 'key' => null];
            }
            $availability = ConfigKeys::availability($key, $state);

            return ['flag' => $flag, 'key' => $availability['settable_now'] ? $key : $availability['use_instead']];
        }, $flags);
    }

    /** @param  list<string>  $flags */
    public static function hint(array $flags, InstanceState $state): string
    {
        $keys = self::keys($flags, $state);
        $settable = array_column(array_filter($keys, static fn (array $entry): bool => $entry['key'] !== null), 'flag');
        $rest = array_column(array_filter($keys, static fn (array $entry): bool => $entry['key'] === null), 'flag');
        $app = array_intersect($rest, self::APP);

        return implode(' ', array_filter([
            $settable === [] ? null : 'Change '.implode(', ', $settable).' with `fsa config set <key> <value>` (data.flags names the key).',
            self::proxySwitch($flags, $state) ? self::PROXY_SWITCH : null,
            $app === [] ? null : implode(', ', $app).' apply only to a new instance: change the organization and its users in the admin panel.',
            in_array('--version', $rest, true) ? 'Another version arrives with `fsa upgrade` (2.0.0-rc.1).' : null,
            in_array('--demo', $rest, true) ? 'Add the demo data to this instance with `fsa demo`.' : null,
            in_array('--phone-https', $flags, true) ? 'Phone HTTPS: `fsa config set phone-https local-ca|off` for the local CA, `fsa tunnel start|stop` for the quick tunnel.' : null,
            array_intersect($flags, ['--smtp-host', '--smtp-port', '--smtp-encryption', '--smtp-username', '--from-address', '--from-name']) !== []
                ? 'Mail: `fsa config set mail smtp --smtp-host <host> [--smtp-username <user> --password-stdin]` changes the server (with a test mail), `fsa config set mail capture` goes back to the catcher.' : null,
            '`fsa config show` lists what `fsa config set` can change; `fsa uninstall` and a fresh install apply anything else.',
        ]));
    }

    /** @param  list<string>  $flags */
    private static function proxySwitch(array $flags, InstanceState $state): bool
    {
        return array_intersect($flags, self::PROXY) !== [] || (in_array('--url', $flags, true) && $state->mode() !== 'proxy');
    }
}
