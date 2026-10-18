<?php

declare(strict_types=1);

namespace App\Domain\Instance\Config;

use App\Domain\Instance\Docker\ComposeProject;
use App\Domain\Instance\Lifecycle\AppCommands;

/**
 * What follows the instance URL when it changes (R21, ADR 0077), from the app-side `instance:url-impact` in a
 * socket-less one-off (KTD25): organizations with SSO, whose SAML entity ID and ACS URL or OIDC redirect URI are
 * built from the URL and must be registered again at their identity provider, and links already sent with the old
 * address (invitations, document links, sign-in links, data exports), which stop working. Only known fields pass
 * through, whatever the app prints. When the one-off fails (database down), the impact is unknown, not empty.
 */
final readonly class UrlImpact
{
    private const array ORGANIZATION_FIELDS = ['id', 'name', 'slug'];

    private const array PROVIDER_FIELDS = ['type', 'name', 'status'];

    private const array LINK_KINDS = ['invitations', 'document_links', 'login_links', 'data_exports'];

    public function __construct(private AppCommands $app) {}

    /** @return array{known: bool, sso_organizations: list<array<string, mixed>>, links: array<string, int>, error?: string} */
    public function assess(ComposeProject $project): array
    {
        $result = $this->app->urlImpact($project);
        if (! $result->ok) {
            return ['known' => false, 'sso_organizations' => [], 'links' => [], 'error' => $result->errorMessage()];
        }
        $organizations = [];
        foreach (is_array($result->data['sso_organizations'] ?? null) ? $result->data['sso_organizations'] : [] as $row) {
            if (! is_array($row)) {
                continue;
            }
            $organizations[] = [
                'organization' => array_intersect_key(is_array($row['organization'] ?? null) ? $row['organization'] : [], array_flip(self::ORGANIZATION_FIELDS)),
                'provider' => array_intersect_key(is_array($row['provider'] ?? null) ? $row['provider'] : [], array_flip(self::PROVIDER_FIELDS)),
                'follows_url' => array_values(array_filter(is_array($row['follows_url'] ?? null) ? $row['follows_url'] : [], is_string(...))),
            ];
        }
        $links = is_array($result->data['links'] ?? null) ? $result->data['links'] : [];

        return [
            'known' => true,
            'sso_organizations' => $organizations,
            'links' => array_map(static fn (string $kind): int => is_int($links[$kind] ?? null) ? $links[$kind] : 0, array_combine(self::LINK_KINDS, self::LINK_KINDS)),
        ];
    }

    /**
     * The confirmation question (and the text of exit 6): the URL change, then each organization with SSO and the
     * number of links that keep the old address.
     *
     * @param  array{known: bool, sso_organizations: list<array<string, mixed>>, links: array<string, int>, error?: string}  $impact
     */
    public static function question(ConfigChange $change, array $impact): string
    {
        $lines = ["Change the instance URL from {$change->oldUrl()} to {$change->newUrl()}?"];
        if (! $impact['known']) {
            $lines[] = 'What follows the URL could not be read ('.($impact['error'] ?? 'the app did not answer').'): organizations with SSO must register the new URL at their identity provider, and links already sent keep the old address.';
        }
        foreach ($impact['sso_organizations'] as $row) {
            $organization = is_array($row['organization'] ?? null) ? $row['organization'] : [];
            $provider = is_array($row['provider'] ?? null) ? $row['provider'] : [];
            $follows = is_array($row['follows_url'] ?? null) ? $row['follows_url'] : [];
            $lines[] = sprintf('- %s (%s SSO): register the new %s at the identity provider.', (string) ($organization['name'] ?? '?'), strtoupper((string) ($provider['type'] ?? 'sso')), implode(' and ', array_map(static fn (mixed $part): string => str_replace('_', ' ', (string) $part), $follows)));
        }
        $links = array_filter($impact['links']);
        if ($links !== []) {
            $lines[] = '- Links already sent keep the old address and stop working: '.implode(', ', array_map(static fn (string $kind, int $count): string => $count.' '.str_replace('_', ' ', $kind), array_keys($links), $links)).'.';
        }
        $lines[] = '- Installed home-screen apps and bookmarks keep the old address.';

        return implode("\n", $lines);
    }
}
