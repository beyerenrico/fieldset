<?php

declare(strict_types=1);

namespace App\Domain\Identity\Sso\Login;

use App\Domain\Audit\AuditLogger;
use App\Domain\Identity\Actions\AcceptInvitation;
use App\Domain\Identity\Actions\AddMember;
use App\Domain\Identity\Actions\SyncMemberGroups;
use App\Domain\Identity\Enums\MembershipStatus;
use App\Domain\Identity\Enums\SystemRole;
use App\Domain\Identity\Models\Group;
use App\Domain\Identity\Models\IdentityProvider;
use App\Domain\Identity\Models\Membership;
use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Sso\Enums\SsoRefusal;
use App\Domain\Identity\Sso\Exceptions\SsoLoginRefused;

/**
 * Identity resolution steps 3 and 4 of ADR 0036 (inside the provider's tenant and a transaction):
 * deactivated users and memberships are refused (SSO never reactivates them), an invited membership is
 * accepted, and a non-member joins with the default role (Field Worker when unset, never Owner) and the
 * default group when JIT is on (`sso.user_provisioned`); otherwise the login is refused with not_member.
 */
final readonly class ProvisionMembership
{
    public function __construct(
        private AddMember $addMember,
        private SyncMemberGroups $syncGroups,
        private AcceptInvitation $acceptInvitation,
        private AuditLogger $audit,
    ) {}

    /** @throws SsoLoginRefused */
    public function handle(IdentityProvider $provider, User $user): Membership
    {
        if (! $user->isActive()) {
            throw new SsoLoginRefused(SsoRefusal::AccountInactive);
        }

        $membership = Membership::query()->where('organization_id', $provider->organization_id)
            ->where('user_id', $user->id)->lockForUpdate()->first();

        if (! $membership instanceof Membership) {
            return $this->provision($provider, $user);
        }

        return match ($membership->status) {
            MembershipStatus::Deactivated => throw new SsoLoginRefused(SsoRefusal::AccountInactive),
            MembershipStatus::Invited => $this->acceptInvitation->handle($membership, null, 'sso'),
            MembershipStatus::Active => $membership,
        };
    }

    /** @throws SsoLoginRefused */
    private function provision(IdentityProvider $provider, User $user): Membership
    {
        if (! $provider->jit_enabled) {
            throw new SsoLoginRefused(SsoRefusal::NotMember);
        }

        $organization = $provider->organization;
        $role = $this->defaultRole($provider);
        $group = $this->defaultGroup($provider);

        $membership = $this->addMember->handle($organization, $user, [$role]);
        if ($group !== null) {
            $this->syncGroups->handle($organization, $user, [$group]);
            $membership->forceFill(['default_group_id' => $group])->save();
        }

        $this->audit->record('sso.user_provisioned', $membership, [
            'provider' => $provider->uuid,
            'user_id' => $user->id,
            'user_created' => $user->wasRecentlyCreated,
            'role' => $role,
            'group_id' => $group,
        ], $user);

        return $membership;
    }

    private function defaultRole(IdentityProvider $provider): string
    {
        $name = $provider->default_role_id === null ? null : Role::query()
            ->where('organization_id', $provider->organization_id)->whereKey($provider->default_role_id)->value('name');

        return is_string($name) && $name !== SystemRole::Owner->value ? $name : SystemRole::FieldWorker->value;
    }

    private function defaultGroup(IdentityProvider $provider): ?int
    {
        $id = $provider->default_group_id === null ? null : Group::query()->whereKey($provider->default_group_id)->value('id');

        return $id === null ? null : (int) $id;
    }
}
