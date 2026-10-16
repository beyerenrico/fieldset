<?php

declare(strict_types=1);

namespace App\Domain\Identity\Actions;

use App\Domain\Audit\AuditLogger;
use App\Domain\Identity\Enums\MembershipStatus;
use App\Domain\Identity\Models\Membership;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Support\RoleAssignmentGuard;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Deactivates or reactivates a membership. Only Owners may deactivate an Owner, and the last active
 * Owner can never be deactivated. Deactivation revokes the member's API tokens for this organization.
 */
final readonly class SetMembershipStatus
{
    public function __construct(
        private RoleAssignmentGuard $guard,
        private AuditLogger $audit,
    ) {}

    public function handle(Membership $membership, MembershipStatus $status, ?User $actor = null): Membership
    {
        $organization = $membership->organization;
        $target = $membership->user;

        DB::transaction(function () use ($membership, $status, $actor, $organization, $target): void {
            if ($status === MembershipStatus::Deactivated && $this->guard->isOwner($target, $organization)) {
                if ($actor instanceof User && ! $this->guard->isOwner($actor, $organization)) {
                    throw ValidationException::withMessages(['status' => __('identity.member.only_owner_deactivates_owner')]);
                }
                $this->guard->ensureAnotherOwnerRemains($organization, $target);
            }

            $membership->forceFill(['status' => $status])->save();

            if ($status === MembershipStatus::Deactivated) {
                $target->tokens()->where('organization_id', $organization->id)->delete();
            }
        });

        $this->audit->record('member.status_changed', $membership, ['status' => $status->value], $actor);

        return $membership;
    }
}
