<?php

declare(strict_types=1);

namespace App\Domain\Identity\Sso\Login;

use App\Domain\Identity\Models\IdentityProvider;
use App\Domain\Identity\Models\SsoDomain;
use App\Domain\Identity\Sso\Enums\IdentityProviderStatus;

/**
 * Domain-level resolution shared by discovery and the password gate (ADR 0036): email domain → the
 * organization holding it verified → that organization's provider. Runs before any tenant is known.
 * Never looks at users, so callers cannot reveal whether an account exists.
 */
final class DomainProviderLookup
{
    /** Statuses in which a provider takes logins: an `error` provider stays usable (the IdP may be back). */
    public const array LOGIN_STATUSES = [IdentityProviderStatus::Active, IdentityProviderStatus::Error];

    public function forEmail(?string $email): ?IdentityProvider
    {
        $domain = EmailDomain::of($email);
        $claim = $domain === null ? null : SsoDomain::verifiedOwnerOf($domain);

        return $claim instanceof SsoDomain ? IdentityProvider::query()->withoutTenant()
            ->where('organization_id', $claim->organization_id)
            ->first() : null;
    }

    /** The provider for the email's domain when it takes logins (active or error). */
    public function loginProviderFor(?string $email): ?IdentityProvider
    {
        $provider = $this->forEmail($email);

        return $provider instanceof IdentityProvider && self::takesLogins($provider) ? $provider : null;
    }

    /** The provider for the email's domain when it enforces SSO. */
    public function enforcingProviderFor(?string $email): ?IdentityProvider
    {
        $provider = $this->loginProviderFor($email);

        return $provider instanceof IdentityProvider && $provider->enforce_sso ? $provider : null;
    }

    public static function takesLogins(IdentityProvider $provider): bool
    {
        return in_array($provider->status, self::LOGIN_STATUSES, true);
    }
}
