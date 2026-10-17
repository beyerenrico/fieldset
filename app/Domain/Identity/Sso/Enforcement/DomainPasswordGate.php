<?php

declare(strict_types=1);

namespace App\Domain\Identity\Sso\Enforcement;

use App\Domain\Identity\Enums\Permission;
use App\Domain\Identity\Models\IdentityProvider;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Sso\Contracts\PasswordAuthGate;
use App\Domain\Identity\Sso\Contracts\SsoMisconfigurationReporter;
use App\Domain\Identity\Sso\Data\DiscoveryResult;
use App\Domain\Identity\Sso\Enums\SsoClient;
use App\Domain\Identity\Sso\Exceptions\SsoRequiredException;
use App\Domain\Identity\Sso\Login\DomainProviderLookup;
use App\Domain\Identity\Sso\Login\EmailDomain;
use App\Domain\Identity\Support\OrganizationPermissions;
use App\Domain\Identity\Tenancy\TenantContext;

/**
 * Enforced SSO follows the email domain (ADR 0036, Enforcement): when the domain is verified by an
 * organization whose provider enforces SSO (and takes logins), password login and reset are refused before
 * any password check — in every organization the person belongs to. The one exemption is the provider's
 * break-glass user while valid: active, an active Owner of that organization and with confirmed MFA (so a
 * break-glass password login always continues into the MFA challenge). A designated but invalid break-glass
 * user is refused and reported as `break_glass_invalid`. Lifting verification of the domain lifts it all.
 */
final readonly class DomainPasswordGate implements PasswordAuthGate
{
    public function __construct(
        private DomainProviderLookup $lookup,
        private OrganizationPermissions $permissions,
        private SsoMisconfigurationReporter $reporter,
        private TenantContext $tenant,
    ) {}

    public function assertPasswordLogin(string $email): void
    {
        $this->assertPasswordAllowed($email);
    }

    public function assertPasswordReset(string $email): void
    {
        $this->assertPasswordAllowed($email);
    }

    /** Whether the user is the valid break-glass user of any provider. */
    public function isBreakGlass(User $user): bool
    {
        return IdentityProvider::query()->withoutTenant()->where('break_glass_user_id', $user->id)->get()
            ->contains(fn (IdentityProvider $provider): bool => $this->isBreakGlassFor($user, $provider));
    }

    /** Whether the user is the provider's designated break-glass user and currently valid for it. */
    public function isBreakGlassFor(User $user, IdentityProvider $provider): bool
    {
        return $provider->break_glass_user_id === $user->id
            && $user->isActive()
            && $user->two_factor_secret !== null
            && $user->two_factor_confirmed_at !== null
            && $user->isMemberOf($provider->organization_id)
            && $this->permissions->has($user, $provider->organization_id, Permission::OrganizationDelete);
    }

    /** True when this user's password login passes the gate only as break-glass (an audited use). */
    public function bypassesEnforcement(User $user): bool
    {
        $provider = $this->lookup->enforcingProviderFor($user->email);

        return $provider instanceof IdentityProvider && $this->isBreakGlassFor($user, $provider);
    }

    /** @throws SsoRequiredException */
    private function assertPasswordAllowed(string $email): void
    {
        $provider = $this->lookup->enforcingProviderFor($email);
        if (! $provider instanceof IdentityProvider) {
            return;
        }

        $email = EmailDomain::normalizeEmail($email);
        $user = $provider->break_glass_user_id === null ? null : User::query()->whereKey($provider->break_glass_user_id)->first();
        if ($user instanceof User && $user->email === $email) {
            if ($this->isBreakGlassFor($user, $provider)) {
                return;
            }
            $this->tenant->run($provider->organization_id, fn () => $this->reporter->report(
                $provider,
                'break_glass_invalid',
                'The break-glass user must be an active Owner with confirmed MFA.',
            ));
        }

        throw new SsoRequiredException(new DiscoveryResult($provider, $provider->redirectUrl(SsoClient::App, $email), true));
    }
}
