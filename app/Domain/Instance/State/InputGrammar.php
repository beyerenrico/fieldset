<?php

declare(strict_types=1);

namespace App\Domain\Instance\State;

use App\Domain\Instance\Compose\ResourceBudget;
use DateTimeZone;

/**
 * The strict grammar of every value a renderer takes from the instance state (plan U3): RFC 1123 host names or
 * IP addresses, http(s) URLs without userinfo, ports, slugs, identifiers and plain text. It is enforced when a
 * value is written into the state (flags, `--from-backup` settings, adopted 1.0 `.env` values all pass
 * `InstanceState::withSetting()`/`withSettings()`) and again whenever a state document is read, so a hand-edited or
 * hostile file never reaches the compose file, an env file or a Caddyfile. Quotes, `$`, backslashes and control
 * characters are refused everywhere: they are what YAML, env-file and Caddyfile injection need.
 */
final class InputGrammar
{
    public const string SLUG = '/^[a-z0-9](?:[a-z0-9-]{0,38}[a-z0-9])?$/D';

    /** Proxy mode's setups (R17, ADR 0076): Traefik or caddy-docker-proxy labels on a shared network, or a loopback port. */
    public const array PROXIES = ['traefik', 'caddy', 'port'];

    /** Phone HTTPS on a local install (E11, R19, R20, ADR 0082): the instance's own CA, or the quick tunnel. */
    public const array PHONE_HTTPS = ['local-ca', 'tunnel'];

    /** A Docker network or container name, as the daemon accepts it. */
    public const string DOCKER_NAME = '/^[A-Za-z0-9][A-Za-z0-9_.-]{0,127}$/D';

    /** A Docker image reference pinned by digest (registry host with optional port, lowercase path, sha256). */
    public const string IMAGE_REFERENCE = '/^(?:[a-zA-Z0-9](?:[a-zA-Z0-9-]*[a-zA-Z0-9])?(?:\.[a-zA-Z0-9](?:[a-zA-Z0-9-]*[a-zA-Z0-9])?)*(?::[0-9]{1,5})?\/)?[a-z0-9]+(?:(?:\.|_|__|-+)[a-z0-9]+)*(?:\/[a-z0-9]+(?:(?:\.|_|__|-+)[a-z0-9]+)*)*@sha256:[0-9a-f]{64}$/D';

    private const string LABEL = '/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/iD';

    private const string IDENTIFIER = '/^[a-z_][a-z0-9_]{0,62}$/D';

    private const string SETTING_KEY = '/^[a-z][a-z0-9_]{0,62}$/D';

    private const string EMAIL_LOCAL = '/^[A-Za-z0-9._%+-]{1,64}$/D';

    private const string URL_PATH = '/^(?:\/[A-Za-z0-9._~%-]*)*$/D';

    private const string UNSAFE = '/[\x00-\x1F\x7F"\'`\\\\$]/u';

    private const int MAX_TEXT = 200;

    private const int MAX_HOSTS = 20;

    /** Grammar of each known setting; unknown settings (from a newer minor version) must be scalar plain text. */
    private const array SETTINGS = [
        'port' => 'port', 'mail_ui_port' => 'port', 'smtp_port' => 'port',
        'domain' => 'host', 'smtp_host' => 'host', 'lan_address' => 'ip', 'extra_hosts' => 'host_list',
        'mail' => ['capture', 'smtp'], 'smtp_scheme' => ['smtp', 'smtps'], 'mail_catcher' => ['kept'], 'size' => ResourceBudget::SIZES,
        'db_database' => 'identifier', 'db_username' => 'identifier',
        'mail_from_address' => 'email', 'mail_from_name' => 'text',
        // Domain mode (U8, ADR 0075): ACME contact and directory, the SHA-256 of the custom ACME CA root the edge
        // trusts (edge/acme-ca-root.pem), and the edge network's private IPv4 subnet.
        'acme_email' => 'email', 'acme_ca' => 'https_url', 'acme_ca_root' => 'sha256', 'edge_subnet' => 'subnet',
        'proxy' => self::PROXIES, 'proxy_network' => 'docker_name', 'proxy_container' => 'docker_name', 'trusted_proxies' => 'ip_list',
        // Phone HTTPS (U14, ADR 0082): local CA on `lan_address` (the edge network) or the quick tunnel on its own network.
        // `phone_https` stays plain text, so a value of a newer version reads (ADR 0081: it still counts as exposure);
        // only `local-ca` and `tunnel` render anything (InstanceSettings), and the flags accept only those (PHONE_HTTPS).
        'phone_https' => 'text', 'tunnel_subnet' => 'subnet',
        // A local-CA deletion that failed on `phone-https off` and is retried (LocalCaDeletion, M11 gate).
        'local_ca_delete' => ['pending'],
        // Backups (U16, ADR 0084): the daily time (`HH:MM` or `off`), how many scheduled backups to keep, and the
        // instance's time zone the schedule is read in.
        'backup_schedule' => 'backup_time', 'backup_retention' => 'retention', 'time_zone' => 'time_zone',
        // Restore (U17, ADR 0085): the fresh storage volume a restore swapped in, and webhooks held after a move.
        'storage_volume' => 'docker_name', 'webhooks_paused' => [true, false],
    ];

    private const array PROBLEMS = [
        'port' => 'must be a port number (1–65535)',
        'host' => 'must be a host name or IP address',
        'ip' => 'must be an IP address',
        'host_list' => 'must be a comma-separated list of host names or IP addresses',
        'identifier' => 'must be a lowercase identifier',
        'email' => 'must be an e-mail address',
        'text' => 'must be plain text without quotes, $, backslashes or control characters',
        'https_url' => 'must be an https URL without credentials, query or fragment',
        'sha256' => 'must be a lowercase SHA-256 hex digest',
        'subnet' => 'must be a private IPv4 subnet (CIDR, /16 to /29)',
        'docker_name' => 'must be a Docker network or container name',
        'ip_list' => 'must be a comma-separated list of IP addresses (single addresses, no ranges)',
        'backup_time' => 'must be a time of day as HH:MM (24 hours) or off',
        'retention' => 'must be a whole number from 1 to 365',
        'time_zone' => 'must be an IANA time zone such as Europe/Berlin or UTC',
    ];

    /** RFC 1123 host name (no wildcard, no trailing dot) or an IPv4/IPv6 address. */
    public static function isHost(mixed $value): bool
    {
        if (! is_string($value) || $value === '' || strlen($value) > 253) {
            return false;
        }
        if (self::isIp($value)) {
            return true;
        }
        if (preg_match('/^[0-9.]+$/D', $value) === 1) {
            return false;
        }

        return array_all(explode('.', $value), static fn (string $label): bool => preg_match(self::LABEL, $label) === 1);
    }

    public static function isIp(mixed $value): bool
    {
        return is_string($value) && filter_var($value, FILTER_VALIDATE_IP) !== false;
    }

    /** http(s) URL with a host, optional port and plain path; no userinfo, query, fragment or unsafe character. */
    public static function isUrl(mixed $value): bool
    {
        if (! is_string($value) || strlen($value) > 2048 || preg_match(self::UNSAFE, $value) === 1 || preg_match('/[\s?#]/', $value) === 1) {
            return false;
        }
        $parts = parse_url($value);
        if (! is_array($parts) || ! in_array($parts['scheme'] ?? null, ['http', 'https'], true) || isset($parts['user']) || isset($parts['pass'])) {
            return false;
        }
        $host = $parts['host'] ?? '';
        $hostOk = str_starts_with($host, '[') ? filter_var(trim($host, '[]'), FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false : self::isHost($host) && ! str_contains($host, ':');

        return $hostOk
            && (! array_key_exists('port', $parts) || self::isPort($parts['port']))
            && preg_match(self::URL_PATH, $parts['path'] ?? '') === 1
            && str_starts_with($value, $parts['scheme'].'://'.$host);
    }

    public static function isHttpsUrl(mixed $value): bool
    {
        return is_string($value) && str_starts_with($value, 'https://') && self::isUrl($value);
    }

    /**
     * A private IPv4 subnet in CIDR notation, /16 to /29, aligned to its prefix: `10.213.4.32/28`. The edge
     * network (U8) and later the tunnel network get a fixed subnet so their peers have fixed addresses.
     */
    public static function isPrivateSubnet(mixed $value): bool
    {
        if (! is_string($value) || preg_match('/^([0-9.]+)\/([0-9]{2})$/D', $value, $parts) !== 1) {
            return false;
        }
        $prefix = (int) $parts[2];
        $long = filter_var($parts[1], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false ? false : ip2long($parts[1]);
        if ($long === false || $prefix < 16 || $prefix > 29 || ($long & ~(-1 << (32 - $prefix)) & 0xFFFFFFFF) !== 0) {
            return false;
        }

        return filter_var($parts[1], FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE) === false
            && filter_var($parts[1], FILTER_VALIDATE_IP, FILTER_FLAG_NO_RES_RANGE) !== false;
    }

    public static function isPort(mixed $value): bool
    {
        return is_int($value) && $value >= 1 && $value <= 65535;
    }

    public static function isSlug(mixed $value): bool
    {
        return is_string($value) && preg_match(self::SLUG, $value) === 1;
    }

    public static function isIdentifier(mixed $value): bool
    {
        return is_string($value) && preg_match(self::IDENTIFIER, $value) === 1;
    }

    public static function isEmail(mixed $value): bool
    {
        if (! is_string($value) || substr_count($value, '@') !== 1) {
            return false;
        }
        [$local, $domain] = explode('@', $value);

        return preg_match(self::EMAIL_LOCAL, $local) === 1 && self::isHost($domain);
    }

    /** Plain single-line text: no quotes, `$`, backticks, backslashes or control characters; at most 200 characters. */
    public static function isText(mixed $value): bool
    {
        return is_string($value) && mb_strlen($value) <= self::MAX_TEXT && preg_match('//u', $value) === 1 && preg_match(self::UNSAFE, $value) !== 1;
    }

    public static function isDockerName(mixed $value): bool
    {
        return is_string($value) && preg_match(self::DOCKER_NAME, $value) === 1;
    }

    /**
     * "a, b" → ["a", "b"] for single IP addresses; null when an entry is empty, a range or not an IP address.
     *
     * @return list<string>|null
     */
    public static function ipList(string $value): ?array
    {
        if (trim($value) === '') {
            return [];
        }
        $addresses = array_map(trim(...), explode(',', $value));

        return count($addresses) <= self::MAX_HOSTS && array_all($addresses, self::isIp(...)) ? $addresses : null;
    }

    public static function isImageReference(mixed $value): bool
    {
        return is_string($value) && preg_match(self::IMAGE_REFERENCE, $value) === 1;
    }

    /**
     * "a, b" → ["a", "b"]; null when an entry is empty, a wildcard, or not a host name or IP address.
     *
     * @return list<string>|null
     */
    public static function hostList(string $value): ?array
    {
        if (trim($value) === '') {
            return [];
        }
        $hosts = array_map(trim(...), explode(',', $value));

        return count($hosts) <= self::MAX_HOSTS && array_all($hosts, self::isHost(...)) ? $hosts : null;
    }

    /** The problem with a setting value, or null when it is valid (null always is: it clears the setting). */
    public static function settingProblem(string $key, mixed $value): ?string
    {
        if (preg_match(self::SETTING_KEY, $key) !== 1) {
            return 'setting names must be lowercase identifiers';
        }
        if ($value === null) {
            return null;
        }
        $rule = self::SETTINGS[$key] ?? null;
        if (is_array($rule)) {
            return in_array($value, $rule, true) ? null : "{$key} must be one of ".implode(', ', $rule);
        }
        if ($rule === null) {
            return self::unknownSettingProblem($key, $value);
        }

        return self::matches($rule, $value) ? null : "{$key} ".self::PROBLEMS[$rule];
    }

    private static function unknownSettingProblem(string $key, mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return "{$key} must be a string, number, boolean or null";
        }

        return ! is_string($value) || self::isText($value) ? null : "{$key} ".self::PROBLEMS['text'];
    }

    private static function matches(string $rule, mixed $value): bool
    {
        return match ($rule) {
            'port' => self::isPort($value),
            'host' => self::isHost($value),
            'ip' => self::isIp($value),
            'host_list' => is_string($value) && self::hostList($value) !== null,
            'ip_list' => is_string($value) && self::ipList($value) !== null,
            'docker_name' => self::isDockerName($value),
            'identifier' => self::isIdentifier($value),
            'email' => self::isEmail($value),
            'https_url' => self::isHttpsUrl($value),
            'sha256' => is_string($value) && preg_match('/^[0-9a-f]{64}$/D', $value) === 1,
            'subnet' => self::isPrivateSubnet($value),
            'backup_time' => is_string($value) && preg_match('/^(?:off|(?:[01]\d|2[0-3]):[0-5]\d)$/D', $value) === 1,
            'retention' => is_int($value) && $value >= 1 && $value <= 365,
            'time_zone' => is_string($value) && in_array($value, DateTimeZone::listIdentifiers(), true),
            default => self::isText($value),
        };
    }
}
