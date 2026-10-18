<?php

declare(strict_types=1);

namespace App\Domain\Instance\Config\Appliers;

use App\Domain\Instance\Config\ConfigChange;
use App\Domain\Instance\Config\ConfigRequest;
use App\Domain\Instance\Config\MailOptions;
use App\Domain\Instance\Config\SettingApplier;
use App\Domain\Instance\Config\SettingHooks;
use App\Domain\Instance\Config\SmtpCredentials;
use App\Domain\Instance\Docker\ComposeProject;
use App\Domain\Instance\HostFacts;
use App\Domain\Instance\Output\InvalidInput;
use App\Domain\Instance\State\EnvFiles;
use App\Domain\Instance\State\InstanceDirectory;
use App\Domain\Instance\State\InstanceSecrets;
use App\Domain\Instance\State\InstanceSettings;
use App\Domain\Instance\State\InstanceState;

/**
 * `fsa config set mail smtp|capture` (R22, R25, KTD13, ADR 0054, ADR 0080).
 *
 * - `smtp`: the server from `--smtp-host` (required from capture mode), `--smtp-port`, `--smtp-encryption starttls|tls`,
 *   an account from `--smtp-username` with the password from `--password-stdin` or `--password-file` (never a flag
 *   value: exit 2), the sender from `--from-address`/`--from-name`. Flags left out keep their values, the credentials
 *   included, except that another `--smtp-host` without an account drops the stored one (`smtp_credentials_dropped`):
 *   an account is never sent to a server it was not given for. App, queue and scheduler get the new mailer; verify
 *   sends a test mail through it (to `--test-to`, else the longest-standing operator) and a refused one reverts to the
 *   previous settings (exit 5). The catcher stays, read-only (nothing reaches its SMTP port), until `--remove-catcher`,
 *   which also removes its volume once the change runs.
 * - `capture`: back to the bundled catcher; it comes back with its volume (or a new one) and a free UI port.
 *
 * The result reports the invitations and password resets capture mode kept from people (MailCompletion).
 */
final readonly class MailApplier implements SettingApplier, SettingHooks
{
    public const string KEY = 'mail';

    public function __construct(
        private InstanceDirectory $directory,
        private LoopbackPorts $ports,
        private MailCompletion $completion,
    ) {}

    public function plan(InstanceState $state, ConfigRequest $request): ConfigChange
    {
        $options = $request->mail ?? new MailOptions;

        return match (strtolower(trim($request->value))) {
            'capture' => $this->capture($state, $options, $request->credentials),
            'smtp' => $this->smtp($state, $options, $request->credentials),
            default => throw new InvalidInput('Invalid value for mail.', 'invalid_value', 'Use `smtp` with --smtp-host (and --smtp-username with --password-stdin for an account), or `capture` for the bundled catcher.'),
        };
    }

    /** The catcher comes back (capture after it was removed): its UI needs a free loopback port again (local, proxy). */
    public function preflight(ConfigChange $change, HostFacts $facts): ConfigChange
    {
        $after = InstanceSettings::of($change->after);
        if (! $after->runsCatcher() || $change->after->mode() === 'domain' || InstanceSettings::of($change->before)->runsCatcher()) {
            return $change;
        }
        $exclude = $after->publishesAppPort() ? [$after->port()] : [];
        $port = $this->ports->choose($change->before, $after->mailUiPort(), InstanceSettings::DEFAULT_MAIL_UI_PORT, $exclude);
        if ($port === $after->mailUiPort()) {
            return $change;
        }

        return $change->withAfter($change->after->withSetting('mail_ui_port', $port))
            ->withWarning('catcher_port_changed', "Port {$after->mailUiPort()} is in use, so the mail catcher UI now listens on 127.0.0.1:{$port}.");
    }

    public function verifySeconds(): int
    {
        return MailCompletion::VERIFY_SECONDS;
    }

    public function verify(ConfigChange $change, ConfigRequest $request, ComposeProject $project): array
    {
        return $this->completion->testMail($change, $request, $project);
    }

    public function completed(ConfigChange $change, ConfigRequest $request, ComposeProject $project): array
    {
        return $this->completion->completed($change, $project);
    }

    /** How a state delivers mail, for `from`/`to` and the audit: never a credential. */
    public static function describe(InstanceState $state): string
    {
        $settings = InstanceSettings::of($state);
        if ($settings->capturesMail()) {
            return 'capture';
        }
        $scheme = $settings->smtpScheme() === InstanceSettings::SMTP_IMPLICIT_TLS ? 'smtps' : 'smtp';

        return "{$scheme}://{$settings->smtpHost()}:{$settings->smtpPort()}".($settings->keepsCatcher() ? ' (catcher kept)' : '');
    }

    private function capture(InstanceState $state, MailOptions $options, ?SmtpCredentials $credentials): ConfigChange
    {
        if (! $options->isEmpty() || $options->testTo !== null || $credentials instanceof SmtpCredentials) {
            throw new InvalidInput('`config set mail capture` takes no SMTP options.', 'invalid_flag', 'Run `fsa config set mail capture` alone; the SMTP settings and credentials are dropped.');
        }
        $after = $state->withSettings(['mail' => null, 'smtp_host' => null, 'smtp_port' => null, 'smtp_scheme' => null, 'mail_catcher' => null]);

        return new ConfigChange(self::KEY, $state, $after, self::describe($state), self::describe($after));
    }

    private function smtp(InstanceState $state, MailOptions $options, ?SmtpCredentials $given): ConfigChange
    {
        $settings = InstanceSettings::of($state);
        $configured = ! $settings->capturesMail();
        $host = $options->smtpHost !== null ? MailValues::host($options->smtpHost) : ($configured ? $settings->smtpHost() : null);
        if ($host === null) {
            throw new InvalidInput('`config set mail smtp` needs the SMTP server.', 'missing_flag', 'Pass --smtp-host <host>, and for an account --smtp-username <user> with --password-stdin or --password-file.');
        }
        $scheme = $options->encryption !== null ? MailValues::scheme($options->encryption) : ($configured ? $settings->smtpScheme() : null);
        $port = match (true) {
            $options->smtpPort !== null => MailValues::port($options->smtpPort),
            $configured && $options->encryption === null => $settings->smtpPort(),
            default => MailValues::defaultPort($scheme),
        };
        $after = $state->withSettings([
            'mail' => 'smtp', 'smtp_host' => $host, 'smtp_port' => $port, 'smtp_scheme' => $scheme,
            'mail_catcher' => ! $options->removeCatcher && $settings->runsCatcher() ? InstanceSettings::CATCHER_KEPT : null,
            ...($options->fromAddress !== null ? ['mail_from_address' => ConfigValues::email($options->fromAddress, 'from-address')] : []),
            ...($options->fromName !== null ? ['mail_from_name' => MailValues::senderName($options->fromName)] : []),
        ]);
        $credentials = MailValues::credentials($options->credentials, $given);
        if ($options->testTo !== null) {
            ConfigValues::email($options->testTo, 'test-to'); // checked now: verify must not fail on a typo
        }
        $current = $this->current($configured);
        $change = new ConfigChange(self::KEY, $state, $after, self::describe($state), self::describe($after), credentials: $credentials instanceof SmtpCredentials && ! $current->equals($credentials) ? $credentials : null);
        if (! $credentials instanceof SmtpCredentials && $current->username !== null && $host !== $settings->smtpHost()) {
            $change = $change->withWarning('smtp_credentials_dropped', "The SMTP account of {$settings->smtpHost()} is not sent to {$host}: the change drops it.", "Pass --smtp-username <user> with --password-stdin or --password-file if {$host} needs an account.");
        }
        $sender = InstanceSettings::of($after)->mailFromAddress();

        return str_ends_with($sender, '@localhost')
            ? $change->withWarning('sender_localhost', "Mail is sent from {$sender}, which most SMTP servers refuse.", 'Pass --from-address with an address of your mail domain.')
            : $change;
    }

    /** The credentials in effect: those in the app env file while SMTP is configured, none in capture mode. */
    private function current(bool $configured): SmtpCredentials
    {
        $secrets = $configured ? InstanceSecrets::fromEnvFiles(new EnvFiles($this->directory)) : null;

        return new SmtpCredentials($secrets?->smtpUsername, $secrets?->smtpPassword);
    }
}
