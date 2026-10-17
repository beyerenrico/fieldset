<?php

declare(strict_types=1);

namespace App\Domain\Identity\Sso\Login;

use App\Domain\Identity\Models\ExternalIdentity;
use App\Domain\Identity\Models\IdentityProvider;
use App\Domain\Identity\Models\SsoDomain;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Sso\Data\ExternalProfile;
use App\Domain\Identity\Sso\Enums\SsoRefusal;
use App\Domain\Identity\Sso\Exceptions\SsoLoginRefused;
use Illuminate\Support\Str;

/**
 * Identity resolution steps 1, 2 and 6 of ADR 0036 (inside the provider's tenant and a transaction):
 * 1. (provider, subject) → the linked user — refused (`domain_not_verified`, detail `domain_moved`) when the
 *    user's email domain is now verified by another organization: that organization owns the address and
 *    its IdP is the one that may sign the person in;
 * 2. otherwise link or create by email, only for a verified email in a domain the provider's organization
 *    holds verified (a new user only when JIT is on; random password, verified email). Linking an existing
 *    account marks its email verified: the IdP vouched for it (an invited account then counts as existing);
 * 6. a linked user's email follows the IdP only within the organization's verified domains and only to a
 *    free address.
 */
final readonly class ResolveSsoUser
{
    public function __construct(private LinkExternalIdentity $link) {}

    /** @throws SsoLoginRefused */
    public function handle(IdentityProvider $provider, ExternalProfile $profile): User
    {
        $identity = ExternalIdentity::query()->with('user')
            ->where('identity_provider_id', $provider->id)->where('subject', $profile->subject)->first();
        if ($identity instanceof ExternalIdentity) {
            return $this->returning($provider, $identity, $profile);
        }

        $email = $this->linkableEmail($provider, $profile);
        $user = User::query()->where('email', $email)->first();
        if (! $user instanceof User) {
            if (! $provider->jit_enabled) {
                throw new SsoLoginRefused(SsoRefusal::NotMember);
            }
            $user = $this->create($email, $profile);
        } elseif ($user->email_verified_at === null) {
            $user->forceFill(['email_verified_at' => now()])->save();
        }
        $this->link->handle($provider, $user, $profile);

        return $user;
    }

    /** @throws SsoLoginRefused */
    private function linkableEmail(IdentityProvider $provider, ExternalProfile $profile): string
    {
        if ($profile->email === null || ! $profile->emailVerified) {
            throw new SsoLoginRefused(SsoRefusal::EmailUnverified);
        }
        if (! $this->isVerifiedDomain($provider, $profile->email)) {
            throw new SsoLoginRefused(SsoRefusal::DomainNotVerified);
        }

        return EmailDomain::normalizeEmail($profile->email);
    }

    /** @throws SsoLoginRefused */
    private function returning(IdentityProvider $provider, ExternalIdentity $identity, ExternalProfile $profile): User
    {
        $user = $identity->user;
        $domain = EmailDomain::of($user->email);
        $owner = $domain === null ? null : SsoDomain::verifiedOwnerOf($domain);
        if ($owner instanceof SsoDomain && $owner->organization_id !== $provider->organization_id) {
            RefusalDetail::set('domain_moved');

            throw new SsoLoginRefused(SsoRefusal::DomainNotVerified);
        }

        $email = $profile->email === null ? null : EmailDomain::normalizeEmail($profile->email);

        if ($email !== null && $email !== $user->email && $profile->emailVerified
            && $this->isVerifiedDomain($provider, $email) && $this->isVerifiedDomain($provider, $user->email)
            && ! User::query()->where('email', $email)->exists()) {
            $user->forceFill(['email' => $email, 'email_verified_at' => now()])->save();
        }

        $identity->forceFill(['last_email' => $email, 'last_login_at' => now()])->save();

        return $user;
    }

    private function create(string $email, ExternalProfile $profile): User
    {
        $user = new User;
        $user->forceFill([
            'name' => $profile->name ?? Str::before($email, '@'),
            'email' => $email,
            'password' => Str::password(64),
            'password_known' => false,
            'email_verified_at' => now(),
        ])->save();

        return $user;
    }

    private function isVerifiedDomain(IdentityProvider $provider, string $email): bool
    {
        $domain = EmailDomain::of($email);

        return $domain !== null && SsoDomain::query()->withoutTenant()
            ->where('organization_id', $provider->organization_id)
            ->where('domain', $domain)->whereNotNull('verified_at')->exists();
    }
}
