<?php

declare(strict_types=1);

namespace App\Domain\Instance\Config;

use App\Domain\Instance\InstanceException;
use App\Domain\Instance\State\InstanceSettings;
use Throwable;

/**
 * Messages of `fsa config set` (ADR 0077): a failure of apply, verify or revert in config terms (the install's smoke
 * test and stack helpers speak of resuming `fsa install`), and the warnings a URL change carries.
 */
final class ConfigFailure
{
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

    private static function hint(string $code, ConfigChange $change): string
    {
        $domain = InstanceSettings::of($change->after)->domain();

        return match ($code) {
            'certificate_failed' => "Check that {$domain} points at this host, that ports 80 and 443 reach it from the internet and that the ACME directory is reachable, then run the command again. Repeated attempts count against the CA's rate limits; --acme-staging is for trials.",
            'services_unhealthy', 'smoke_failed', 'url_check_failed' => 'Read `fsa logs <service>` for the cause, fix it, then run the command again.',
            default => 'Fix the cause, then run the command again; `fsa status` shows the instance.',
        };
    }
}
