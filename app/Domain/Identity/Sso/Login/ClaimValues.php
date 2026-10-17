<?php

declare(strict_types=1);

namespace App\Domain\Identity\Sso\Login;

use App\Domain\Identity\Models\IdentityProvider;
use App\Domain\Identity\Models\IdentityProviderMapping;
use App\Domain\Identity\Sso\Enums\IdentityProviderType;

/**
 * Sanitizes IdP claims for ExternalProfile::$claims (ADR 0036): only the claims a login needs (groups, hd,
 * tid, amr and every claim a mapping of the provider reads), each as a bounded list of strings.
 */
final class ClaimValues
{
    private const int MAX_VALUES = 500;

    private const int MAX_LENGTH = 255;

    /**
     * @param  array<array-key, mixed>  $raw
     * @param  list<string>  $names
     * @return array<string, list<string>>
     */
    public static function pick(array $raw, array $names): array
    {
        $claims = [];
        foreach (array_unique($names) as $name) {
            if ($name !== '' && array_key_exists($name, $raw) && $raw[$name] !== null) {
                $claims[$name] = self::list($raw[$name]);
            }
        }

        return $claims;
    }

    /** @return list<string> */
    public static function list(mixed $value): array
    {
        $values = is_array($value) ? array_values($value) : [$value];
        $strings = [];
        foreach ($values as $item) {
            if (is_string($item) || is_int($item) || is_float($item) || is_bool($item)) {
                $strings[] = mb_substr(is_bool($item) ? ($item ? 'true' : 'false') : (string) $item, 0, self::MAX_LENGTH);
            }
        }

        return array_slice(array_values(array_unique($strings)), 0, self::MAX_VALUES);
    }

    /** The provider's groups claim (OIDC `groups_claim`, SAML `groups_attribute`; default "groups"). */
    public static function groupsClaim(IdentityProvider $provider): string
    {
        $value = $provider->config[$provider->type === IdentityProviderType::Saml ? 'groups_attribute' : 'groups_claim'] ?? null;

        return is_string($value) && trim($value) !== '' ? trim($value) : 'groups';
    }

    /** @return list<string> claim names the provider's mappings read */
    public static function mappingClaims(IdentityProvider $provider): array
    {
        return array_values(IdentityProviderMapping::query()->withoutTenant()
            ->where('identity_provider_id', $provider->id)
            ->distinct()->orderBy('claim')->pluck('claim')
            ->map(static fn (mixed $claim): string => (string) $claim)->all());
    }
}
