<?php

declare(strict_types=1);

namespace App\Domain\Instance\Install;

use App\Domain\Instance\InstanceException;
use App\Domain\Instance\Output\ExitCode;
use App\Domain\Instance\Output\InvalidInput;
use App\Domain\Instance\Restore\BackupSet;
use App\Domain\Instance\Restore\LegacyEnv;
use App\Domain\Instance\Restore\PreparedBackup;
use App\Domain\Instance\State\InputGrammar;
use App\Domain\Instance\State\InstanceState;

/**
 * The settings split of `fsa install --from-backup` (R42, KTD18, plan U17, ADR 0085). Portable settings come from the
 * backup (its settings.json, or the allow-listed values of a 1.0 `env`) and pass the same checks as `config set`; host-bound
 * ones (ports, LAN address, phone HTTPS, proxy wiring, tunnel, CA, owner) come from the flags and the host. Without a mode
 * flag the backup's mode applies: its domain (domain mode) or local mode; proxy mode needs its flags again (they name
 * this host's proxy). A moved instance always starts with capture mail and paused webhooks: the backup's SMTP server is
 * reported, not used, until the operator confirms the move.
 */
final readonly class FromBackupSettings
{
    /** Portable settings a move keeps (PortableSettings::KEYS without mail delivery, which starts captured). */
    public const array KEPT = ['extra_hosts', 'mail_from_address', 'mail_from_name', 'backup_schedule', 'backup_retention', 'time_zone', 'size', 'db_database', 'db_username'];

    /** ACME settings taken over only together with the backup's own domain. */
    private const array ACME = ['acme_email', 'acme_ca'];

    /**
     * The request with the backup's mode when no mode flag was given.
     *
     * @throws InvalidInput exit 2 for a proxy-mode backup without proxy flags
     */
    public static function effective(InstallRequest $request, PreparedBackup $backup): InstallRequest
    {
        if ($request->domain() !== null || $request->proxy() !== null || $request->phoneHttps() !== null) {
            return $request;
        }
        [$mode, $domain] = self::origin($backup);
        if ($mode === 'proxy') {
            throw new InvalidInput('The backup comes from an instance behind a proxy; its proxy wiring belongs to the old host.', 'proxy_flags_required', 'Pass --behind-proxy traefik|caddy|port with --url (and --proxy-network), or --domain <name>, or nothing else for local mode by passing --port.');
        }
        if ($mode !== 'domain' || $domain === null || $request->port() !== null) {
            return $request;
        }
        $request = $request->with('domain', $domain);
        foreach (self::ACME as $key) {
            $value = $backup->settings['settings'][$key] ?? null;
            if (is_string($value) && $value !== '') {
                $request = $request->with($key, $value);
            }
        }

        return $request;
    }

    /**
     * The backup's settings over the state the install wrote, then capture mail, paused webhooks and the fresh volume; the
     * backup's recipients only with `--keep-recipients`.
     */
    public static function overlay(InstanceState $state, PreparedBackup $backup, string $freshVolume, bool $keepRecipients = false): InstanceState
    {
        $portable = $backup->isLegacy() ? self::legacy($backup) : array_intersect_key(self::fsaSettings($backup), array_flip(self::KEPT));
        $state = $state->withSettings(array_filter($portable, static fn (mixed $value): bool => $value !== null && $value !== ''));
        $recipients = $keepRecipients ? self::recipients($backup)['kept'] : [];
        if ($recipients !== []) {
            $state = $state->withRecipients($recipients);
        }

        return $state->withSettings(['mail' => null, 'mail_catcher' => null, 'smtp_host' => null, 'smtp_port' => null, 'smtp_scheme' => null, 'webhooks_paused' => true, 'storage_volume' => $freshVolume]);
    }

    /**
     * The backup's age recipients (review S4): eligible only from an authenticated archive and only in age's format; an
     * unauthenticated archive could otherwise make every later backup readable by whoever wrote it. Even then they are
     * kept only when the operator confirms them (`--keep-recipients`, M12 gate S2).
     *
     * @return array{kept: list<string>, dropped: list<string>} kept: the eligible ones
     */
    public static function recipients(PreparedBackup $backup): array
    {
        $listed = array_values(array_filter((array) ($backup->settings['recipients'] ?? []), is_string(...)));
        $valid = array_values(array_filter($listed, static fn (string $recipient): bool => preg_match(InstanceState::RECIPIENT, $recipient) === 1));
        $kept = $backup->authenticated() ? $valid : [];

        return ['kept' => $kept, 'dropped' => array_values(array_diff($listed, $kept))];
    }

    /**
     * The SMTP server the backed-up instance delivered through, for the operator to switch to after the move.
     *
     * @return array{host: string, port: int|null, scheme: string|null, command: string}|null
     */
    public static function pendingMail(PreparedBackup $backup): ?array
    {
        $settings = $backup->isLegacy() ? self::legacyMail($backup) : self::fsaSettings($backup);
        $host = $settings['smtp_host'] ?? null;
        if (! is_string($host) || $host === '' || ($backup->isLegacy() === false && ($settings['mail'] ?? null) !== 'smtp')) {
            return null;
        }
        $port = is_numeric($settings['smtp_port'] ?? null) ? (int) $settings['smtp_port'] : null;
        $scheme = is_string($settings['smtp_scheme'] ?? null) ? $settings['smtp_scheme'] : null;

        return ['host' => $host, 'port' => $port, 'scheme' => $scheme,
            'command' => 'fsa config set mail smtp --smtp-host '.$host.($port === null ? '' : ' --smtp-port '.$port).' --smtp-username <account> --password-file <file>'];
    }

    /** @return array{0: string|null, 1: string|null} the backed-up instance's mode and domain */
    private static function origin(PreparedBackup $backup): array
    {
        if (! $backup->isLegacy()) {
            $domain = self::fsaSettings($backup)['domain'] ?? null;

            return [is_string($backup->settings['mode'] ?? null) ? $backup->settings['mode'] : null, is_string($domain) ? $domain : null];
        }
        $url = LegacyEnv::read(self::legacyFile($backup))['APP_URL'] ?? '';
        $parts = parse_url($url);
        $host = is_array($parts) ? ($parts['host'] ?? null) : null;
        $public = is_string($host) && ($parts['scheme'] ?? null) === 'https' && InputGrammar::isHost($host) && filter_var($host, FILTER_VALIDATE_IP) === false && $host !== 'localhost';

        return $public ? ['domain', $host] : ['local', null];
    }

    /** @return array<string, mixed> */
    private static function fsaSettings(PreparedBackup $backup): array
    {
        $settings = $backup->settings['settings'] ?? [];

        return is_array($settings) ? $settings : [];
    }

    /** @return array<string, mixed> the 1.0 sender (the rest of a 1.0 `.env` is never read) */
    private static function legacy(PreparedBackup $backup): array
    {
        $env = LegacyEnv::read(self::legacyFile($backup));

        $sender = ['mail_from_address' => $env['MAIL_FROM_ADDRESS'] ?? null, 'mail_from_name' => $env['MAIL_FROM_NAME'] ?? null];

        // A 1.0 value the 2.0 grammar refuses is left out (the default sender applies), never a failed move.
        return array_filter($sender, static fn (?string $value, string $key): bool => $value !== null && InputGrammar::settingProblem($key, $value) === null, ARRAY_FILTER_USE_BOTH);
    }

    /** @return array<string, string> */
    private static function legacyMail(PreparedBackup $backup): array
    {
        $env = LegacyEnv::read(self::legacyFile($backup));

        return array_filter(['smtp_host' => $env['MAIL_HOST'] ?? null, 'smtp_port' => $env['MAIL_PORT'] ?? null], is_string(...));
    }

    private static function legacyFile(PreparedBackup $backup): string
    {
        if (! $backup->legacyEnv) {
            return '';
        }
        $contents = @file_get_contents($backup->set.'/'.BackupSet::LEGACY_ENV);
        if ($contents === false) {
            throw new InstanceException('The 1.0 backup\'s env file cannot be read.', ExitCode::InvalidInput, 'backup_unreadable');
        }

        return $contents;
    }
}
