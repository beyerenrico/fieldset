<?php

declare(strict_types=1);

namespace App\Domain\Instance\Config\Appliers;

use App\Domain\Instance\InstanceException;
use App\Domain\Instance\Output\InvalidInput;
use App\Domain\Instance\State\InputGrammar;
use App\Domain\Instance\State\InstanceSettings;
use App\Domain\Instance\State\InstanceState;

/**
 * The grammar of `fsa config set` values (ADR 0077): the same rules as the install flags (InputGrammar, ADR 0075,
 * ADR 0076), normalized the same way, with messages that name the setting. Exit 2 for anything else.
 */
final class ConfigValues
{
    public static function domain(string $value): string
    {
        $domain = strtolower(trim($value));
        if (! InputGrammar::isHost($domain) || InputGrammar::isIp($domain) || ! str_contains($domain, '.')) {
            throw new InvalidInput('Invalid value for domain.', 'invalid_value', 'Use a domain name such as forms.example.com (not an IP address).');
        }

        return $domain;
    }

    /** An http(s) origin without path, query or credentials, lower case without a trailing slash (ADR 0076). */
    public static function origin(string $value): string
    {
        $url = strtolower(rtrim(trim($value), '/'));
        $path = parse_url($url, PHP_URL_PATH);
        if (! InputGrammar::isUrl($url) || ! in_array($path, [null, '', '/'], true)) {
            throw new InvalidInput('Invalid value for url.', 'invalid_value', 'Use the public URL your proxy serves, such as https://forms.example.com (no path, query or credentials).');
        }

        return $url;
    }

    public static function port(string $value): int
    {
        $value = trim($value);
        if (! ctype_digit($value) || ! InputGrammar::isPort((int) $value)) {
            throw new InvalidInput('Invalid value for port.', 'invalid_value', 'Use a port number between 1 and 65535.');
        }

        return (int) $value;
    }

    public static function email(string $value, string $key): string
    {
        $email = strtolower(trim($value));
        if (! InputGrammar::isEmail($email)) {
            throw new InvalidInput("Invalid value for {$key}.", 'invalid_value', 'Pass an e-mail address such as ops@example.com.');
        }

        return $email;
    }

    /**
     * The ACME directory as stored: null for Let's Encrypt production (the default), the staging URL for `staging`,
     * else an https URL.
     */
    public static function acmeCa(string $value): ?string
    {
        $value = trim($value);
        $ca = match (strtolower($value)) {
            'production', InstanceSettings::ACME_PRODUCTION => null,
            'staging' => InstanceSettings::ACME_STAGING,
            default => $value,
        };
        if ($ca !== null && ! InputGrammar::isHttpsUrl($ca)) {
            throw new InvalidInput('Invalid value for acme-ca.', 'invalid_value', 'Use production, staging or the https URL of an ACME directory.');
        }

        return $ca;
    }

    /**
     * The key applies only in some modes (exit 2 with the way to get there).
     *
     * @param  list<string>  $modes
     */
    public static function assertMode(InstanceState $state, array $modes, string $key, string $hint): void
    {
        if (! in_array($state->mode(), $modes, true)) {
            throw new InvalidInput("`{$key}` does not apply in {$state->mode()} mode.", 'not_in_this_mode', $hint);
        }
    }

    /**
     * R61: phone HTTPS (local CA or tunnel), domain and proxy exclude each other. Phone HTTPS arrives with U14, which
     * records it in the `phone_https` setting; the hint names the command that turns it off.
     *
     * @throws InstanceException exit 2
     */
    public static function assertNoPhoneHttps(InstanceState $state, string $target): void
    {
        $phone = $state->setting('phone_https');
        if (is_string($phone) && $phone !== '') {
            $off = match ($phone) {
                'local-ca' => 'Turn the local CA off first with `fsa config set phone-https off --yes`',
                'tunnel' => 'Stop the quick tunnel first with `fsa tunnel stop`',
                default => 'Turn phone HTTPS off first (`fsa config set phone-https off --yes`, or `fsa tunnel stop` for the quick tunnel)',
            };
            throw new InvalidInput("Phone HTTPS ({$phone}) and {$target} exclude each other.", 'invalid_combination', $off.', then change the mode.');
        }
    }
}
