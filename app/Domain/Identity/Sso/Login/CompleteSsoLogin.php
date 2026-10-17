<?php

declare(strict_types=1);

namespace App\Domain\Identity\Sso\Login;

use App\Domain\Identity\Models\IdentityProvider;
use App\Domain\Identity\Models\Membership;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Sso\Data\ExternalProfile;
use App\Domain\Identity\Sso\Exceptions\SsoLoginRefused;
use App\Domain\Identity\Tenancy\TenantContext;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Identity resolution and JIT for OIDC and SAML (ADR 0036), in one transaction inside the provider's tenant:
 * resolve or link/create the user (ResolveSsoUser), refuse deactivated accounts and handle the membership
 * (ProvisionMembership), then re-sync the claim mappings (SyncClaimMappings) of that membership. A flow started
 * from another organization's invitation passes its membership id ($invitation, see ProvisionMembership).
 * Signing in is the caller's job.
 * Two first logins of the same person can race on the unique (provider, subject) or users.email: the loser
 * retries once and then finds the winner's rows.
 */
final readonly class CompleteSsoLogin
{
    public function __construct(
        private ResolveSsoUser $resolve,
        private ProvisionMembership $membership,
        private SyncClaimMappings $mappings,
        private TenantContext $tenant,
    ) {}

    /** @throws SsoLoginRefused */
    public function handle(IdentityProvider $p, ExternalProfile $profile, ?int $invitation = null): User
    {
        try {
            return $this->attempt($p, $profile, $invitation);
        } catch (UniqueConstraintViolationException) {
            return $this->attempt($p, $profile, $invitation);
        }
    }

    /**
     * @throws SsoLoginRefused
     * @throws UniqueConstraintViolationException
     */
    private function attempt(IdentityProvider $provider, ExternalProfile $profile, ?int $invitation): User
    {
        return DB::transaction(fn (): User => $this->tenant->run($provider->organization_id, function () use ($provider, $profile, $invitation): User {
            $user = $this->resolve->handle($provider, $profile);
            if ($this->membership->handle($provider, $user, $invitation) instanceof Membership) {
                $this->mappings->handle($provider, $user, $profile);
            }

            return $user;
        }));
    }
}
