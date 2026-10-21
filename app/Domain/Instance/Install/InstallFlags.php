<?php

declare(strict_types=1);

namespace App\Domain\Instance\Install;

use App\Domain\Instance\Config\ConfigKeys;
use App\Domain\Instance\State\InstanceState;

/**
 * What a repeated `fieldset install` with different flags tells an agent (exit 2 `use_config_set`, ADR 0078): per flag the
 * `fieldset config set` key that changes it on this instance, or none, and a hint that says plainly what is possible.
 * Proxy mode cannot be entered or re-wired on an installed instance: the data moves through uninstall's final backup
 * (a 2.0 backup, ADR 0073 as amended by the M12 gate) and `install.sh --dir <new> --from-backup <backup>` into a fresh
 * directory; another release arrives with `fieldset upgrade`.
 */
final class InstallFlags
{
    public const string PROXY_SWITCH = 'Switching to proxy mode, or changing its setup or network, is not possible on an installed instance: it needs `fieldset uninstall` and a fresh `fieldset install --behind-proxy traefik|caddy|port --url <origin>`. To carry the data over, run `fieldset uninstall --keep-backups` (it writes a final backup into backups/ and keeps that directory), then `install.sh --dir <dir> --from-backup <dir>/backups/final-<time>` with the proxy flags, adding `--app-key-file <dir>/backups/final-<time>.app-key.env` when backups are not encrypted, or `--identity-file`, the passphrase and `--accept-unauthenticated` when they are (the APP keys are inside the encrypted backup).';

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
            $settable === [] ? null : 'Change '.implode(', ', $settable).' with `fieldset config set <key> <value>` (data.flags names the key).',
            self::proxySwitch($flags, $state) ? self::PROXY_SWITCH : null,
            $app === [] ? null : implode(', ', $app).' apply only to a new instance: change the organization and its users in the admin panel.',
            in_array('--version', $rest, true) ? 'Move this instance to another release with `fieldset upgrade --to <version>`.' : null,
            in_array('--demo', $rest, true) ? 'Add the demo data to this instance with `fieldset demo`.' : null,
            in_array('--phone-https', $flags, true) ? 'Phone HTTPS: `fieldset config set phone-https local-ca|off` for the local CA, `fieldset tunnel start|stop` for the quick tunnel.' : null,
            array_intersect($flags, ['--smtp-host', '--smtp-port', '--smtp-encryption', '--smtp-username', '--from-address', '--from-name']) !== []
                ? 'Mail: `fieldset config set mail smtp --smtp-host <host> --smtp-username <user> --from-address <address> --password-stdin` changes the server (with a test mail; leave the account out for a server without one), `fieldset config set mail capture` goes back to the catcher.' : null,
            '`fieldset config show` lists what `fieldset config set` can change; `fieldset uninstall` and a fresh install apply anything else.',
        ]));
    }

    /** @param  list<string>  $flags */
    private static function proxySwitch(array $flags, InstanceState $state): bool
    {
        return array_intersect($flags, self::PROXY) !== [] || (in_array('--url', $flags, true) && $state->mode() !== 'proxy');
    }
}
