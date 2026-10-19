<?php

declare(strict_types=1);

namespace App\Domain\Instance\Config;

use App\Domain\Instance\InstanceException;
use App\Domain\Instance\State\InstanceSettings;
use Throwable;

/**
 * Messages of `fsa config set` (ADR 0077, ADR 0078): a failure of apply, verify or revert in config terms (the
 * install's smoke test and stack helpers speak of resuming `fsa install`), whether repeating the same command can
 * help, and the warnings a URL change carries.
 */
final class ConfigFailure
{
    /**
     * Causes worth repeating unchanged, with the seconds to wait first: the engine was unreachable or a docker call
     * failed on its own, or the run was interrupted. Every other cause (a certificate the CA refused, services that do
     * not come up with the new settings, a failed check, a bug) needs a fix first: repeating it only reverts again,
     * and for ACME it counts against the CA's rate limits.
     */
    private const array TRANSIENT = ['docker_unavailable' => 30, 'docker_command_failed' => 15, 'interrupted' => 0];

    /** @return array{code: string, message: string, hint: string, data: array<string, mixed>} */
    public static function describe(Throwable $failure, ConfigChange $change): array
    {
        $code = $failure instanceof InstanceException ? $failure->errorCode : 'internal_error';

        return [
            'code' => $code,
            'message' => $failure->getMessage(),
            'hint' => self::hint($code, $change),
            'data' => $failure instanceof InstanceException ? $failure->data : ['exception' => $failure::class],
        ];
    }

    /**
     * Whether the same command may simply run again: `retry_after` (seconds) for transient causes, else `fix_first`
     * with what to fix (ADR 0078).
     *
     * @return array{retryable: bool, retry_after?: int, fix_first?: string}
     */
    public static function retry(string $code, string $hint): array
    {
        $after = self::TRANSIENT[$code] ?? null;

        return $after === null ? ['retryable' => false, 'fix_first' => $hint] : ['retryable' => true, 'retry_after' => $after];
    }

    /**
     * @param  array<string, mixed>|null  $impact
     * @return list<array{0: string, 1: string, 2: string|null}>
     */
    public static function impactWarnings(ConfigChange $change, ?array $impact): array
    {
        if (! $change->changesUrl()) {
            return [];
        }
        $warnings = [['url_changed', "The instance URL is now {$change->newUrl()} (was {$change->oldUrl()}): installed home-screen apps, bookmarks and links already sent keep the old address.", 'Send new invitations or document links where needed.']];
        $organizations = is_array($impact['sso_organizations'] ?? null) ? $impact['sso_organizations'] : [];
        if ($organizations !== []) {
            $names = implode(', ', array_map(static fn (mixed $row): string => is_array($row) && is_array($row['organization'] ?? null) ? (string) ($row['organization']['name'] ?? '?') : '?', $organizations));
            $warnings[] = ['sso_urls_changed', "Organizations with SSO must register the new URLs at their identity provider: {$names}.", 'Their admins find the new entity ID, ACS URL or redirect URI under SSO settings.'];
        }
        if (($impact['known'] ?? true) === false) {
            $warnings[] = ['impact_unknown', 'Which organizations use SSO could not be read before the change.', 'Check the SSO settings of every organization.'];
        }

        return $warnings;
    }

    /** The one-line fix for a failure code, in config terms. */
    public static function hint(string $code, ConfigChange $change): string
    {
        $domain = InstanceSettings::of($change->after)->domain();

        return match ($code) {
            'certificate_failed' => "Check that {$domain} points at this host, that ports 80 and 443 reach it from the internet and that the ACME directory is reachable, then run the command again. Repeated attempts count against the CA's rate limits; --acme-staging is for trials.",
            'services_unhealthy', 'smoke_failed', 'url_check_failed' => 'Read `fsa logs <service>` for the cause, fix it, then run the command again.',
            'test_mail_failed' => self::testMailHint($change),
            'interrupted' => 'The command was stopped (a signal: Ctrl-C, a closed session or a tool timeout) and restored the previous settings; run it again when it can finish.',
            'docker_unavailable', 'docker_command_failed' => 'Check that the Docker engine runs (`docker info`), then run the command again.',
            default => 'Fix the cause, then run the command again; `fsa status` shows the instance.',
        };
    }

    /** A refused test mail: what to check, with the sender most servers refuse named (as InstallSmoke does for install). */
    private static function testMailHint(ConfigChange $change): string
    {
        $sender = InstanceSettings::of($change->after)->mailFromAddress();
        $localhost = str_ends_with($sender, '@localhost') ? " Mail is sent from {$sender}, which most servers refuse: pass --from-address with an address of your mail domain." : '';

        return 'Check the SMTP host, port, encryption (starttls needs a server that offers STARTTLS; a relay without TLS takes --smtp-encryption none), user name and password against what the server answered (data.failure).'
            .$localhost.' Then run the command again; the previous mail settings stay until a test mail goes through.';
    }
}
