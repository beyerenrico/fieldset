<?php

declare(strict_types=1);

namespace App\Domain\Identity\Sso\Login;

use App\Domain\Identity\Models\IdentityProvider;
use App\Domain\Identity\Models\SsoDomain;
use App\Domain\Identity\Sso\Data\ExternalProfile;
use App\Domain\Identity\Sso\Enums\IdentityProviderType;
use App\Domain\Identity\Sso\Enums\OidcFlavor;
use App\Domain\Identity\Sso\Enums\SsoRefusal;
use App\Domain\Identity\Sso\Exceptions\SsoLoginRefused;
use App\Domain\Identity\Sso\Providers\OidcSettings;
use InvalidArgumentException;
use Laravel\Socialite\Contracts\User as SocialiteUser;

/**
 * Turns a validated OIDC login (id_token claims, merged userinfo) into an ExternalProfile and applies the
 * flavor rules that are refusals (ADR 0036): Google needs `email_verified` and an `hd` that the
 * organization holds verified; generic OIDC needs `email_verified` unless the audited opt-in
 * `trust_unverified_email` is set; Entra's email is the UPN (`preferred_username`), which the directory
 * verified. `claims.email_verified_by` (claim|upn|trusted) goes into the login audit. A token issued by
 * Google or Microsoft only counts under that flavor (OidcSettings::requiredFlavor(), also refused at config
 * time), so the `hd` and `tid` rules can never be skipped.
 */
final readonly class OidcProfileFactory
{
    /** @throws SsoLoginRefused */
    public function make(IdentityProvider $provider, OidcSettings $settings, SocialiteUser $user): ExternalProfile
    {
        $raw = $this->raw($user);
        $this->assertFlavorFits($settings, $raw);
        $email = $user->getEmail();
        $email = is_string($email) && $email !== '' ? EmailDomain::normalizeEmail($email) : null;
        $verifiedBy = $this->verification($provider, $settings, $raw, $email);
        $claims = ClaimValues::pick($raw, [$settings->groupsClaim, 'hd', 'tid', 'amr', ...ClaimValues::mappingClaims($provider)]);
        if (isset($claims[$settings->groupsClaim])) {
            $claims['groups'] = $claims[$settings->groupsClaim];
        }

        return new ExternalProfile(
            IdentityProviderType::Oidc,
            is_string($raw['iss'] ?? null) ? $raw['iss'] : $settings->issuer,
            (string) $user->getId(),
            $email,
            $verifiedBy !== null,
            $this->name($user, $raw),
            [...$claims, 'email_verified_by' => $verifiedBy],
        );
    }

    /**
     * How the email was verified, or null when it was not.
     *
     * @param  array<array-key, mixed>  $raw
     *
     * @throws SsoLoginRefused
     */
    private function verification(IdentityProvider $provider, OidcSettings $settings, array $raw, ?string $email): ?string
    {
        $claimed = filter_var($raw['email_verified'] ?? false, FILTER_VALIDATE_BOOLEAN);

        return match ($settings->flavor) {
            OidcFlavor::Entra => $email === null ? null : 'upn',
            OidcFlavor::Google => $this->google($provider, $raw, $claimed),
            OidcFlavor::Generic => $claimed ? 'claim' : ($settings->trustUnverifiedEmail && $email !== null ? 'trusted' : null),
        };
    }

    /**
     * @param  array<array-key, mixed>  $raw
     *
     * @throws SsoLoginRefused
     */
    private function google(IdentityProvider $provider, array $raw, bool $claimed): string
    {
        $hd = is_string($raw['hd'] ?? null) ? EmailDomain::of('x@'.$raw['hd']) : null;
        $verified = $hd !== null && SsoDomain::query()->withoutTenant()
            ->where('organization_id', $provider->organization_id)->where('domain', $hd)->whereNotNull('verified_at')->exists();

        if (! $verified) {
            throw new SsoLoginRefused(SsoRefusal::DomainNotVerified);
        }
        if (! $claimed) {
            throw new SsoLoginRefused(SsoRefusal::EmailUnverified);
        }

        return 'claim';
    }

    /**
     * @param  array<array-key, mixed>  $raw
     *
     * @throws InvalidArgumentException a configuration fault (status=error, reported)
     */
    private function assertFlavorFits(OidcSettings $settings, array $raw): void
    {
        $required = OidcSettings::requiredFlavor(is_string($raw['iss'] ?? null) ? $raw['iss'] : $settings->issuer);
        if ($required instanceof OidcFlavor && $required !== $settings->flavor) {
            throw new InvalidArgumentException(OidcSettings::CONFIG_ERROR.' a token of this issuer needs the '.$required->value.' flavor.');
        }
    }

    /** @return array<array-key, mixed> */
    private function raw(SocialiteUser $user): array
    {
        $raw = method_exists($user, 'getRaw') ? $user->getRaw() : [];
        if (! is_array($raw) || ! is_string($raw['sub'] ?? null) || $raw['sub'] === '') {
            throw new InvalidArgumentException('JWT: Missing required sub claim.');
        }

        return $raw;
    }

    /** @param  array<array-key, mixed>  $raw */
    private function name(SocialiteUser $user, array $raw): ?string
    {
        $name = $user->getName();
        if (is_string($name) && trim($name) !== '') {
            return mb_substr(trim($name), 0, 255);
        }
        $parts = array_filter([$raw['given_name'] ?? null, $raw['family_name'] ?? null], is_string(...));

        return $parts === [] ? null : mb_substr(implode(' ', $parts), 0, 255);
    }
}
