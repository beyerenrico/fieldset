<?php

declare(strict_types=1);

namespace App\Domain\Instance\Config\Appliers;

use App\Domain\Instance\Config\SmtpCredentials;
use App\Domain\Instance\Output\InvalidInput;
use App\Domain\Instance\State\InputGrammar;
use App\Domain\Instance\State\InstanceSettings;
use SensitiveParameter;

/**
 * The grammar of the `fsa config set mail smtp` flags (R25, ADR 0080), exit 2 for anything else: host names as for
 * every other setting (InputGrammar), the encryption as `starttls` (STARTTLS when the server offers it, port 587) or
 * `tls` (implicit TLS, port 465), and credentials that an env file can hold (no line break or NUL).
 */
final class MailValues
{
    /** `--smtp-encryption` => the stored `smtp_scheme` (null: STARTTLS when offered). */
    public const array ENCRYPTIONS = ['starttls' => null, 'tls' => InstanceSettings::SMTP_IMPLICIT_TLS];

    private const int MAX_USERNAME = 255;

    private const int MAX_PASSWORD = 1024;

    public static function host(string $value): string
    {
        $host = strtolower(trim($value));
        if (! InputGrammar::isHost($host)) {
            throw new InvalidInput('Invalid value for --smtp-host.', 'invalid_value', 'Use the SMTP server\'s host name or IP address, such as smtp.example.com.');
        }

        return $host;
    }

    public static function port(string $value): int
    {
        $value = trim($value);
        if (! ctype_digit($value) || ! InputGrammar::isPort((int) $value)) {
            throw new InvalidInput('Invalid value for --smtp-port.', 'invalid_value', 'Use a port number between 1 and 65535, usually 587 (STARTTLS) or 465 (TLS).');
        }

        return (int) $value;
    }

    /** The stored scheme for an encryption name. */
    public static function scheme(string $value): ?string
    {
        $value = strtolower(trim($value));
        if (! array_key_exists($value, self::ENCRYPTIONS)) {
            throw new InvalidInput('Invalid value for --smtp-encryption.', 'invalid_value', 'Use starttls (STARTTLS when the server offers it, port 587) or tls (implicit TLS, port 465).');
        }

        return self::ENCRYPTIONS[$value];
    }

    public static function defaultPort(?string $scheme): int
    {
        return $scheme === InstanceSettings::SMTP_IMPLICIT_TLS ? InstanceSettings::DEFAULT_SMTPS_PORT : InstanceSettings::DEFAULT_SMTP_PORT;
    }

    public static function encryption(?string $scheme): string
    {
        return $scheme === InstanceSettings::SMTP_IMPLICIT_TLS ? 'tls' : 'starttls';
    }

    public static function senderName(string $value): string
    {
        $name = trim($value);
        if ($name === '' || ! InputGrammar::isText($name)) {
            throw new InvalidInput('Invalid value for --from-name.', 'invalid_value', 'Use plain text without quotes, $, backslashes or line breaks.');
        }

        return $name;
    }

    /**
     * Credentials from `--smtp-username` and the password from stdin or a file: both, or neither.
     *
     * @throws InvalidInput
     */
    public static function credentials(bool $usernameGiven, ?SmtpCredentials $given): ?SmtpCredentials
    {
        if (! $usernameGiven) {
            if ($given instanceof SmtpCredentials && $given->password !== null) {
                throw new InvalidInput('A password needs --smtp-username.', 'invalid_flag', 'Pass --smtp-username <user> together with --password-stdin or --password-file.');
            }

            return null;
        }
        $username = $given?->username;
        $password = $given?->password;
        if ($username === null || $username === '' || mb_strlen($username) > self::MAX_USERNAME || preg_match('//u', $username) !== 1 || preg_match('/[\x00-\x1F\x7F]/', $username) === 1) {
            throw new InvalidInput('Invalid value for --smtp-username.', 'invalid_value', 'Use the SMTP account name, without line breaks or control characters.');
        }
        self::assertPassword($password);

        return new SmtpCredentials($username, $password);
    }

    /** @throws InvalidInput */
    private static function assertPassword(#[SensitiveParameter] ?string $password): void
    {
        if ($password === null || $password === '') {
            throw new InvalidInput('--smtp-username needs the password.', 'password_required', 'Pipe it with --password-stdin or pass --password-file=<path> (mode 0600); never as a flag value.');
        }
        if (strlen($password) > self::MAX_PASSWORD || preg_match('/[\r\n\x00]/', $password) === 1) {
            throw new InvalidInput('The SMTP password contains a line break or NUL byte, or is longer than 1024 bytes.', 'invalid_value', 'Pass the password alone, without line breaks (one trailing newline is ignored).');
        }
    }
}
