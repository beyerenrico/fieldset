<?php

declare(strict_types=1);

namespace App\Domain\Instance\Install;

use App\Domain\Instance\InstanceException;
use App\Domain\Instance\LocalCa\LanAddress;
use App\Domain\Instance\Output\InvalidInput;
use App\Domain\Instance\Release\ReleaseException;
use App\Domain\Instance\Release\ReleaseVersion;
use App\Domain\Instance\State\InputGrammar;

/**
 * The grammar of the `fieldset install` flags (§7, R26): each value validated and normalized, exit 2 `invalid_flag` with a
 * hint otherwise. The SMTP flags use the grammar of `fieldset config set mail smtp` (InstallMail, MailValues).
 */
final class InstallInputs
{
    private const int MAX_NAME = 120;

    /**
     * @return string|int the validated, normalized value
     *
     * @throws InvalidInput
     */
    public static function validate(string $key, string $value): string|int
    {
        $flag = InstallRequest::FLAGS[$key] ?? throw new InstanceException("Unknown install input [{$key}].");
        if (array_key_exists($key, InstallMail::FLAGS)) {
            return InstallMail::validate($key, $value);
        }
        $value = $key === 'url' ? strtolower(rtrim($value, '/')) : $value;
        $valid = match ($key) {
            'organization', 'owner_name' => $value !== '' && mb_strlen($value) <= self::MAX_NAME && preg_match('/[\x00-\x1F\x7F]/u', $value) !== 1 && preg_match('//u', $value) === 1,
            'owner_email' => InputGrammar::isEmail(strtolower($value)),
            'locale' => in_array($value, InstallRequest::LOCALES, true),
            'port' => ctype_digit($value) && InputGrammar::isPort((int) $value),
            'version' => self::isVersion($value),
            'acme_email' => InputGrammar::isEmail(strtolower($value)),
            'acme_ca' => InputGrammar::isHttpsUrl($value),
            'acme_ca_root' => preg_match('/^[0-9a-f]{64}$/D', $value) === 1,
            'proxy' => in_array($value, InputGrammar::PROXIES, true),
            'url' => self::isProxyUrl($value),
            'proxy_network' => InputGrammar::isDockerName($value),
            'trusted_proxy' => InputGrammar::isIp($value) || InputGrammar::isDockerName($value),
            'phone_https' => in_array($value, InputGrammar::PHONE_HTTPS, true),
            'lan_address' => LanAddress::usable($value),
            default => InputGrammar::isHost(strtolower($value)) && ! InputGrammar::isIp($value) && str_contains($value, '.'),
        };
        if (! $valid) {
            throw new InvalidInput("Invalid value for {$flag}.", 'invalid_flag', self::hint($key));
        }

        return match ($key) {
            'port' => (int) $value,
            'owner_email', 'domain', 'acme_email' => strtolower($value),
            default => $value,
        };
    }

    /** An http(s) origin: routing under a path prefix is not supported (ADR 0074), so no path but `/`. */
    private static function isProxyUrl(string $value): bool
    {
        $path = parse_url($value, PHP_URL_PATH);

        return InputGrammar::isUrl($value) && in_array($path, [null, '', '/'], true);
    }

    private static function isVersion(string $value): bool
    {
        try {
            ReleaseVersion::parse($value);

            return true;
        } catch (ReleaseException) {
            return false;
        }
    }

    private static function hint(string $key): string
    {
        return match ($key) {
            'organization', 'owner_name' => 'Use at most 120 characters without line breaks.',
            'owner_email' => 'Pass an e-mail address such as owner@example.com.',
            'locale' => 'Use de or en.',
            'port' => 'Use a port number between 1 and 65535.',
            'version' => 'Use a release version such as 2.0.0 or 2.0.0-rc.1.',
            'acme_email' => 'Pass an e-mail address such as ops@example.com.',
            'acme_ca' => 'Pass the https URL of an ACME directory, such as https://acme-v02.api.letsencrypt.org/directory.',
            'acme_ca_root' => 'Pass a PEM file with the CA certificate of the ACME directory.',
            'proxy' => 'Use traefik, caddy (caddy-docker-proxy) or port.',
            'url' => 'Use the public URL your proxy serves, such as https://forms.example.com (no path, query or credentials).',
            'proxy_network' => 'Use the name of the Docker network your proxy is attached to (docker network ls).',
            'trusted_proxy' => 'Use the proxy container\'s name or its IP address on that network.',
            'phone_https' => 'Use local-ca (the instance\'s own CA on your LAN address) or tunnel (a quick tunnel through Cloudflare).',
            'lan_address' => 'Use the IPv4 address of this machine in your LAN, such as 192.168.1.20 (not 127.0.0.1 and not an IPv6 address).',
            default => 'Use a domain name such as forms.example.com.',
        };
    }
}
