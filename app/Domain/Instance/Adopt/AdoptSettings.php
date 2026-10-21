<?php

declare(strict_types=1);

namespace App\Domain\Instance\Adopt;

use App\Domain\Instance\Install\InstallRequest;
use App\Domain\Instance\InstanceException;
use App\Domain\Instance\Output\ExitCode;
use App\Domain\Instance\Output\InvalidInput;
use App\Domain\Instance\State\InputGrammar;

/**
 * What an adopted instance takes from the 1.0 `.env` (KTD20, plan U19, ADR 0087). The mode follows APP_URL and APP_PORT:
 * 1.0 publishes its app on 127.0.0.1:APP_PORT only, so a URL with another host than the loopback means a reverse proxy
 * on the host forwards to that port: proxy mode with `--behind-proxy port`, the same URL and port (the proxy keeps
 * working unchanged); a loopback URL is local mode on APP_PORT. APP_KEY (and APP_PREVIOUS_KEYS) stay, so the data stays
 * readable and sessions stay valid; the database and its user keep their 1.0 names (the copied cluster has them). The
 * sender is kept when the 2.0 grammar takes it; mail starts captured, with 1.0's SMTP server named for `config set mail`.
 * Everything that decides how the instance runs comes from fieldset's own rendering: APP_DEBUG, SSO_ALLOW_INSECURE_IDP and
 * SSO_DNS_DRIVER are forced safe and TRUSTED_PROXIES follows the mode (LegacyEnv::FORCED).
 */
final class AdoptSettings
{
    /** Host names that mean "this machine". */
    private const array LOOPBACK = ['localhost', '127.0.0.1', '::1', '[::1]'];

    /**
     * The install request adopt runs the preflight with. While 1.0's app holds APP_PORT the doctor cannot probe it, so the
     * port is set after the preflight (AdoptPreflight); otherwise it must be free.
     *
     * @throws InstanceException exit 7 for a URL the instance cannot serve
     */
    public static function request(LegacyCheckout $checkout, ?string $release, bool $portHeld): InstallRequest
    {
        $request = InstallRequest::fromOptions(['release' => $release]);
        $url = $checkout->url();
        try {
            if (self::behindProxy($url)) {
                $request = $request->with('proxy', 'port')->with('url', (string) $url);
            }

            return $portHeld ? $request : $request->with('port', (string) $checkout->port());
        } catch (InvalidInput $e) {
            throw new InstanceException("The 1.0 APP_URL [{$url}] is not a URL an instance can serve (an http(s) origin without a path).", ExitCode::Incompatible, 'unsupported_url', 'Set APP_URL in the checkout\'s .env to the public origin (e.g. https://forms.example.com), restart 1.0, then run adopt again.', false, [], $e);
        }
    }

    /** Whether a reverse proxy serves 1.0: its URL names another host than the loopback. */
    public static function behindProxy(?string $url): bool
    {
        $host = is_string($url) ? parse_url($url, PHP_URL_HOST) : null;

        return is_string($host) && $host !== '' && ! in_array(strtolower($host), self::LOOPBACK, true) && ! str_ends_with(strtolower($host), '.localhost');
    }

    /**
     * Settings over the ones install derives: the database names and the sender.
     *
     * @return array<string, string>
     */
    public static function overlay(LegacyCheckout $checkout): array
    {
        $sender = array_filter([
            'mail_from_address' => $checkout->value('MAIL_FROM_ADDRESS'),
            'mail_from_name' => $checkout->value('MAIL_FROM_NAME'),
        ], static fn (?string $value, string $key): bool => $value !== null && InputGrammar::settingProblem($key, $value) === null, ARRAY_FILTER_USE_BOTH);

        return ['db_database' => $checkout->database(), 'db_username' => $checkout->username(), ...$sender];
    }

    /**
     * The SMTP server 1.0 delivered through, for `fieldset config set mail smtp` (its credentials are never read).
     *
     * @return array{host: string, port: int|null, command: string}|null
     */
    public static function pendingMail(LegacyCheckout $checkout): ?array
    {
        $host = $checkout->value('MAIL_HOST');
        if ($host === null || ! InputGrammar::isHost(strtolower($host)) || in_array(strtolower($host), ['mailpit', 'localhost', '127.0.0.1'], true)) {
            return null;
        }
        $port = $checkout->value('MAIL_PORT');
        $port = $port !== null && ctype_digit($port) && InputGrammar::isPort((int) $port) ? (int) $port : null;

        return ['host' => $host, 'port' => $port,
            'command' => 'fieldset config set mail smtp --smtp-host '.$host.($port === null ? '' : ' --smtp-port '.$port).' --smtp-username <account> --password-file <file>'];
    }
}
