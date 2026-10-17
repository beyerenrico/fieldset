<?php

declare(strict_types=1);

namespace App\Domain\Identity\Sso\Login;

use App\Domain\Identity\Sso\Data\ExternalProfile;
use App\Domain\Identity\Sso\Enums\IdentityProviderType;

/** Plain-array form of an ExternalProfile (SAML ticket, test-mode result). */
final class ProfileArray
{
    /** @return array{via: string, issuer: string, subject: string, email: string|null, email_verified: bool, name: string|null, claims: array<string, mixed>} */
    public static function from(ExternalProfile $profile): array
    {
        return [
            'via' => $profile->via->value, 'issuer' => $profile->issuer, 'subject' => $profile->subject, 'email' => $profile->email,
            'email_verified' => $profile->emailVerified, 'name' => $profile->name, 'claims' => $profile->claims,
        ];
    }

    public static function to(mixed $data): ?ExternalProfile
    {
        if (! is_array($data) || ! is_string($data['issuer'] ?? null) || ! is_string($data['subject'] ?? null)) {
            return null;
        }
        $via = IdentityProviderType::tryFrom(is_string($data['via'] ?? null) ? $data['via'] : '');
        $claims = is_array($data['claims'] ?? null) ? $data['claims'] : [];

        return $via === null ? null : new ExternalProfile(
            $via,
            $data['issuer'],
            $data['subject'],
            is_string($data['email'] ?? null) ? $data['email'] : null,
            ($data['email_verified'] ?? false) === true,
            is_string($data['name'] ?? null) ? $data['name'] : null,
            array_filter($claims, is_string(...), ARRAY_FILTER_USE_KEY),
        );
    }
}
