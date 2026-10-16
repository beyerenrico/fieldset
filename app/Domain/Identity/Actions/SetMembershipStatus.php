<?php

declare(strict_types=1);

namespace App\Domain\Identity\Actions;

use App\Domain\Audit\AuditLogger;
use App\Domain\Identity\Enums\MembershipStatus;
use App\Domain\Identity\Enums\Permission;
use App\Domain\Identity\Models\Membership;
use App\Domain\Identity\Support\OrganizationPermissions;
use DomainException;

/** Deactivates or reactivates a membership; the last active Owner can never be deactivated. */
final class SetMembershipStatus
{
    public function __construct(
        private readonly OrganizationPermissions $permissions,
        private readonly AuditLogger $audit,
    ) {}

    public function handle(Membership $membership, MembershipStatus $status): Membership
    {
        if ($status === MembershipStatus::Deactivated && $this->isLastOwner($membership)) {
            throw new DomainException(__('identity.member.last_owner'));
        }

        $membership->forceFill(['status' => $status])->save();

        if ($status === MembershipStatus::Deactivated) {
            // Revoke API tokens so integrations lose access immediately.
            $membership->user->tokens()->delete();
        }

        $this->audit->record('member.status_changed', $membership, ['status' => $status->value]);

        return $membership;
    }

    private function isLastOwner(Membership $membership): bool
    {
        if (! $this->permissions->has($membership->user, $membership->organization_id, Permission::OrganizationDelete)) {
            return false;
        }

        $owners = Membership::query()
            ->where('organization_id', $membership->organization_id)
            ->where('status', MembershipStatus::Active->value)
            ->with('user')
            ->get()
            ->filter(fn (Membership $m): bool => $this->permissions->has($m->user, $m->organization_id, Permission::OrganizationDelete));

        return $owners->count() <= 1;
    }
}
