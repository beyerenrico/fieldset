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
use App\Domain\Identity\Sso\Admin\SsoTargets;
use App\Domain\Identity\Sso\Enums\SsoRefusal;
use App\Domain\Identity\Sso\Exceptions\SsoLoginRefused;

/**
 * Identity resolution steps 3 and 4 of ADR 0036 (inside the provider's tenant and a transaction):
 * deactivated users and memberships are refused (SSO never reactivates them), an invited membership is
 * accepted, and a non-member joins with the default role (Field Worker when unset or Owner-level: Owner or
 * any role with organization.delete) and the default group when JIT is on (`sso.user_provisioned`);
 * otherwise the login is refused with not_member.
 *
 * Invitation of another organization: enforcement follows the email domain, so a person invited to
 * organization B whose domain organization A verified and enforces must sign in at A's IdP to accept. When the
 * flow came from that invitation (still pending, for this user, in another organization) and the person is
 * not a member of A, the login neither joins A by JIT nor fails as not_member: it only authenticates, so the
 * invitation can be accepted (`sso.jit_skipped`, reason `invitation`). A later ordinary login through A's IdP
 * follows ADR 0036 JIT as before.
 */
final readonly class ProvisionMembership
{
    public function __construct(
        private AddMember $addMember,
        private SyncMemberGroups $syncGroups,
        private AcceptInvitation $acceptInvitation,
        private SsoTargets $targets,
        private AuditLogger $audit,
    ) {}

    /**
     * The membership in the provider's organization, or null when the login only accepts another
     * organization's invitation (see above).
     *
     * @param  int|null  $invitation  the invited membership the flow was started for
     *
     * @throws SsoLoginRefused
     */
    public function handle(IdentityProvider $provider, User $user, ?int $invitation = null): ?Membership
    {
        if (! $user->isActive()) {
            throw new SsoLoginRefused(SsoRefusal::AccountInactive);
        }

        $membership = Membership::query()->where('organization_id', $provider->organization_id)
            ->where('user_id', $user->id)->lockForUpdate()->first();

        if (! $membership instanceof Membership) {
            return $this->acceptsElsewhere($provider, $user, $invitation) ? null : $this->provision($provider, $user);
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

    /** The flow accepts a pending invitation of this user in another organization (audited). */
    private function acceptsElsewhere(IdentityProvider $provider, User $user, ?int $invitation): bool
    {
        $invited = $invitation === null ? null : Membership::query()->whereKey($invitation)
            ->where('user_id', $user->id)->where('status', MembershipStatus::Invited->value)
            ->where('organization_id', '!=', $provider->organization_id)->first();
        if (! $invited instanceof Membership) {
            return false;
        }

        $this->audit->record('sso.jit_skipped', $provider, [
            'provider' => $provider->uuid,
            'user_id' => $user->id,
            'reason' => 'invitation',
            'invitation_organization_id' => $invited->organization_id,
        ], $user);

        return true;
    }

    private function defaultRole(IdentityProvider $provider): string
    {
        $role = $provider->default_role_id === null ? null : Role::query()
            ->where('organization_id', $provider->organization_id)->whereKey($provider->default_role_id)->first();

        return $role instanceof Role && ! $this->targets->isOwnerLevel($role) ? $role->name : SystemRole::FieldWorker->value;
    }

    private function defaultGroup(IdentityProvider $provider): ?int
    {
        $id = $provider->default_group_id === null ? null : Group::query()->whereKey($provider->default_group_id)->value('id');

        return $id === null ? null : (int) $id;
    }
}
