<?php

declare(strict_types=1);

namespace App\Domain\Instance\Install;

use App\Domain\Instance\Config\Appliers\ConfigValues;
use App\Domain\Instance\Config\Appliers\MailValues;
use App\Domain\Instance\Config\SmtpCredentials;
use App\Domain\Instance\InstanceException;
use App\Domain\Instance\Output\ExitCode;
use App\Domain\Instance\Output\InvalidInput;
use App\Domain\Instance\State\EnvFiles;
use App\Domain\Instance\State\InstanceDirectory;
use App\Domain\Instance\State\InstanceSecrets;
use App\Domain\Instance\State\InstanceSettings;
use App\Domain\Instance\State\InstanceState;
use Closure;

/**
 * `fsa install --smtp-host … [--smtp-port] [--smtp-encryption starttls|tls] [--smtp-username … --password-stdin|
 * --password-file] [--from-address] [--from-name]` (§7, E8, R11, R22, R25, ADR 0080 amendment): the instance starts in
 * SMTP mode instead of capture mode. The flags use the grammar of `fsa config set mail smtp` (MailValues).
 *
 * - The server flags are consumed by the doctor phase (journaled there under `mail`, so a resume keeps them): no catcher
 *   port is chosen and no catcher image checked. The state phase writes them into the state and renders app, queue
 *   and scheduler with MAIL_* for that server; no Mailpit service exists.
 * - The account is consumed by the state phase: the user name and the password live only in the app roles' env files,
 *   never in the journal (which records `smtp_credentials: 1`), the state or any output. A resume before the state
 *   phase needs the password again; after it, a different account is `flag_already_applied`.
 * - A finished instance compares with its state as it is now (`fsa config set mail` may have changed it), the account
 *   with the env files.
 */
final class InstallMail
{
    /** Request key => command-line flag; `smtp_credentials` (1 when an account was given) stands for --smtp-username. */
    public const array FLAGS = [
        'smtp_host' => '--smtp-host', 'smtp_port' => '--smtp-port', 'smtp_encryption' => '--smtp-encryption',
        'from_address' => '--from-address', 'from_name' => '--from-name', 'smtp_credentials' => '--smtp-username',
    ];

    /** Command option => request key (the account is read apart: SmtpInput). */
    public const array OPTIONS = [
        'smtp-host' => 'smtp_host', 'smtp-port' => 'smtp_port', 'smtp-encryption' => 'smtp_encryption',
        'from-address' => 'from_address', 'from-name' => 'from_name',
    ];

    /** The server flags, consumed by the doctor phase. */
    public const array SERVER_FLAGS = ['smtp_host', 'smtp_port', 'smtp_encryption', 'from_address', 'from_name'];

    /**
     * @return string|int the validated value (exit 2 otherwise)
     *
     * @throws InvalidInput
     */
    public static function validate(string $key, string $value): string|int
    {
        return match ($key) {
            'smtp_host' => MailValues::host($value),
            'smtp_port' => MailValues::port($value),
            'smtp_encryption' => MailValues::encryption(MailValues::scheme($value)),
            'from_address' => ConfigValues::email($value, '--from-address'),
            'from_name' => MailValues::senderName($value),
            default => throw new InstanceException("Install mail input [{$key}] has no flag value (the account: InstallRequest::withSmtpCredentials)."),
        };
    }

    /**
     * The SMTP flags fit together, checked before a password is read: every SMTP flag needs --smtp-host, a user name a
     * password source and a password source a user name (exit 2).
     *
     * @param  array<string, string|int>  $explicit  the request's explicit inputs
     *
     * @throws InvalidInput
     */
    public static function assertFlags(array $explicit, bool $username, ?string $passwordSource): void
    {
        $others = array_values(array_intersect_key(self::FLAGS, array_diff_key($explicit, ['smtp_host' => true, 'smtp_credentials' => true])));
        $others = [...$others, ...($username ? ['--smtp-username'] : []), ...($passwordSource === null ? [] : ['--password-'.$passwordSource])];
        if (! array_key_exists('smtp_host', $explicit) && $others !== []) {
            throw new InstanceException(implode(', ', $others).' need(s) --smtp-host.', ExitCode::InvalidInput, 'input_required', 'Pass --smtp-host <server> to install with your SMTP server, or leave the SMTP flags out to start in capture mode (`fsa config set mail smtp` switches later).', false, ['missing' => ['--smtp-host']]);
        }
        if ($username && $passwordSource === null) {
            throw new InvalidInput('--smtp-username needs the password.', 'password_required', 'Pass --password-file=<path> (mode 0600), or --password-stdin; never as a flag value.');
        }
        if (! $username && $passwordSource !== null) {
            throw new InvalidInput('A password needs --smtp-username.', 'invalid_flag', 'Pass --smtp-username <user> together with --password-stdin or --password-file.');
        }
    }

    /**
     * The doctor phase's `mail` entry: the settings for the state, null for capture mode (no --smtp-host).
     *
     * @param  array<string, string|int>  $explicit
     * @return array<string, string|int>|null
     */
    public static function settings(array $explicit): ?array
    {
        $host = $explicit['smtp_host'] ?? null;
        if (! is_string($host)) {
            return null;
        }
        $scheme = MailValues::scheme((string) ($explicit['smtp_encryption'] ?? 'starttls'));

        return array_filter([
            'mail' => 'smtp', 'smtp_host' => $host, 'smtp_port' => $explicit['smtp_port'] ?? MailValues::defaultPort($scheme), 'smtp_scheme' => $scheme,
            'mail_from_address' => $explicit['from_address'] ?? null, 'mail_from_name' => $explicit['from_name'] ?? null,
        ], static fn (string|int|null $value): bool => $value !== null);
    }

    /** @param  array<string, mixed>  $preflight  the doctor phase's data */
    public static function apply(InstanceState $state, array $preflight): InstanceState
    {
        $mail = $preflight['mail'] ?? null;
        /** @var array<string, string|int> $mail */

        return is_array($mail) && is_string($mail['smtp_host'] ?? null) ? $state->withSettings($mail) : $state;
    }

    /**
     * What the doctor phase applied, keyed like InstallRequest, for a resume's flag check (null: applied as not set).
     *
     * @param  array<string, mixed>  $doctor
     * @return array<string, string|int|null>
     */
    public static function applied(array $doctor): array
    {
        $mail = is_array($doctor['mail'] ?? null) ? $doctor['mail'] : [];
        $smtp = is_string($mail['smtp_host'] ?? null);
        $value = static fn (string $key): string|int|null => is_string($mail[$key] ?? null) || is_int($mail[$key] ?? null) ? $mail[$key] : null;

        return [
            'smtp_host' => $value('smtp_host'), 'smtp_port' => $value('smtp_port'),
            'smtp_encryption' => $smtp ? MailValues::encryption(is_string($mail['smtp_scheme'] ?? null) ? $mail['smtp_scheme'] : null) : null,
            'from_address' => $value('mail_from_address'), 'from_name' => $value('mail_from_name'),
        ];
    }

    /**
     * How a finished instance delivers mail now, keyed like InstallRequest (the sender as in effect); the account is
     * compared through comparator().
     *
     * @return array<string, string|int|null>
     */
    public static function installed(InstanceState $state): array
    {
        $settings = InstanceSettings::of($state);
        $smtp = ! $settings->capturesMail();

        return [
            'smtp_host' => $smtp ? $settings->smtpHost() : null, 'smtp_port' => $smtp ? $settings->smtpPort() : null,
            'smtp_encryption' => $smtp ? MailValues::encryption($settings->smtpScheme()) : null,
            'from_address' => $settings->mailFromAddress(), 'from_name' => $settings->mailFromName(), 'smtp_credentials' => null,
        ];
    }

    /** The SMTP account in the app env file (none in capture mode or before the state phase). */
    public static function stored(InstanceDirectory $directory): SmtpCredentials
    {
        $secrets = InstanceSecrets::fromEnvFiles(new EnvFiles($directory));

        return new SmtpCredentials($secrets?->smtpUsername, $secrets?->smtpPassword);
    }

    /**
     * Compares the account given now with the stored one (`smtp_credentials`), everything else as `$equals` does.
     *
     * @param  (Closure(string, string|int|null, string|int): bool)|null  $equals
     * @return Closure(string, string|int|null, string|int): bool
     */
    public static function comparator(?SmtpCredentials $given, SmtpCredentials $stored, ?Closure $equals = null): Closure
    {
        return static function (string $key, string|int|null $recorded, string|int $value) use ($given, $stored, $equals): bool {
            if ($key === 'smtp_credentials') {
                return $given instanceof SmtpCredentials && $given->equals($stored);
            }

            return $equals instanceof Closure ? $equals($key, $recorded, $value) : (string) $recorded === (string) $value;
        };
    }

    /**
     * A resume before the state phase renders the account: the password must come again (it is never journaled).
     *
     * @throws InvalidInput
     */
    public static function assertAccountKnown(InstallRequest $request, bool $stateWritten): void
    {
        if (! $stateWritten && ($request->explicit()['smtp_credentials'] ?? null) === 1 && ! $request->smtpCredentials() instanceof SmtpCredentials) {
            throw new InvalidInput('The interrupted install needs the SMTP password again.', 'password_required', 'Run `fsa install` again with the same --smtp-username and --password-file (or --password-stdin).');
        }
    }

    /**
     * Mail sent from forms@localhost (no --from-address, no --domain) is refused by most SMTP servers.
     *
     * @param  array<string, string|int>  $explicit
     * @return list<array{code: string, message: string, hint: string}>
     */
    public static function warnings(array $explicit): array
    {
        if (! is_string($explicit['smtp_host'] ?? null) || isset($explicit['from_address']) || isset($explicit['domain'])) {
            return [];
        }

        return [['code' => 'sender_localhost', 'message' => 'Mail is sent from forms@localhost, which most SMTP servers refuse.', 'hint' => 'Pass --from-address with an address of your mail domain.']];
    }
}
