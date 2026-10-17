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
use App\Domain\Identity\Sso\Login\SsoStartLink;
use App\Domain\Identity\Support\OrganizationPermissions;
use App\Domain\Identity\Tenancy\TenantContext;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use SensitiveParameter;

/**
 * Enforced SSO follows the email domain (ADR 0036, Enforcement): when the domain is verified by an
 * organization whose provider enforces SSO (and takes logins), password login and reset are refused before
 * any password check — in every organization the person belongs to. The one exemption is the provider's
 * break-glass user while valid: active, an active Owner of that organization and with confirmed MFA (so a
 * break-glass password login always continues into the MFA challenge). A designated but invalid break-glass
 * user is refused and reported as `break_glass_invalid`. Lifting verification of the domain lifts it all.
 *
 * Password logins use assertPasswordLoginWith(): an enforced address answers sso_required unless it is the
 * valid break-glass user with the correct password, and every enforced address costs exactly one password
 * hash — so neither the answer nor its timing tells which address is the break-glass account.
 */
final readonly class DomainPasswordGate implements PasswordAuthGate
{
    public function __construct(
        private DomainProviderLookup $lookup,
        private OrganizationPermissions $permissions,
        private SsoMisconfigurationReporter $reporter,
        private TenantContext $tenant,
        private SsoStartLink $links,
    ) {}

    /** Email-only check (invitations, flows without a password); prefer assertPasswordLoginWith() for logins. */
    public function assertPasswordLogin(string $email): void
    {
        $this->assertPasswordAllowed($email);
    }

    /**
     * The password login check for the Fortify and Filament logins, run before their own credential check.
     *
     * @throws SsoRequiredException when the domain enforces SSO, unless these are the valid break-glass
     *                              user's correct credentials
     */
    public function assertPasswordLoginWith(string $email, #[SensitiveParameter] string $password): void
    {
        $provider = $this->lookup->enforcingProviderFor($email);
        if (! $provider instanceof IdentityProvider) {
            return;
        }

        $email = EmailDomain::normalizeEmail($email);
        $breakGlass = $this->breakGlassUser($provider);
        // Always one hash, against the break-glass user's hash when there is one: same cost for every address.
        $matches = Hash::check($password, $breakGlass->password ?? $this->dummyHash());
        if ($breakGlass instanceof User && $breakGlass->email === $email && $matches) {
            if ($this->isBreakGlassFor($breakGlass, $provider)) {
                return;
            }
            $this->reportInvalidBreakGlass($provider);
        }

        throw $this->required($provider, $email);
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
        $user = $this->breakGlassUser($provider);
        if ($user instanceof User && $user->email === $email) {
            if ($this->isBreakGlassFor($user, $provider)) {
                return;
            }
            $this->reportInvalidBreakGlass($provider);
        }

        throw $this->required($provider, $email);
    }

    private function breakGlassUser(IdentityProvider $provider): ?User
    {
        return $provider->break_glass_user_id === null ? null : User::query()->whereKey($provider->break_glass_user_id)->first();
    }

    private function reportInvalidBreakGlass(IdentityProvider $provider): void
    {
        $this->tenant->run($provider->organization_id, fn () => $this->reporter->report(
            $provider,
            'break_glass_invalid',
            'The break-glass user must be an active Owner with confirmed MFA.',
        ));
    }

    private function required(IdentityProvider $provider, string $email): SsoRequiredException
    {
        return new SsoRequiredException(new DiscoveryResult($provider, $this->links->url($provider, SsoClient::App, $email), true));
    }

    /** A hash with the current hashing settings, for addresses without a break-glass user to compare with. */
    private function dummyHash(): string
    {
        $key = 'sso:password-gate:dummy-hash:'.hash('sha256', (string) json_encode(config('hashing')));

        return (string) Cache::rememberForever($key, static fn (): string => Hash::make(Str::random(40)));
    }
}
