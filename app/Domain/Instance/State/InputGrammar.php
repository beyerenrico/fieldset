<?php

declare(strict_types=1);

namespace App\Domain\Instance\State;

use App\Domain\Instance\Compose\ResourceBudget;

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
        'mail' => ['capture', 'smtp'], 'smtp_scheme' => ['smtp', 'smtps'], 'size' => ResourceBudget::SIZES,
        'db_database' => 'identifier', 'db_username' => 'identifier',
        'mail_from_address' => 'email', 'mail_from_name' => 'text',
    ];

    private const array PROBLEMS = [
        'port' => 'must be a port number (1–65535)',
        'host' => 'must be a host name or IP address',
        'ip' => 'must be an IP address',
        'host_list' => 'must be a comma-separated list of host names or IP addresses',
        'identifier' => 'must be a lowercase identifier',
        'email' => 'must be an e-mail address',
        'text' => 'must be plain text without quotes, $, backslashes or control characters',
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
            'identifier' => self::isIdentifier($value),
            'email' => self::isEmail($value),
            default => self::isText($value),
        };
    }
}
