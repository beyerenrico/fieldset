<?php

declare(strict_types=1);

namespace App\Domain\Identity\Actions;

use App\Domain\Audit\AuditLogger;
use App\Domain\Identity\Models\Membership;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Support\RoleAssignmentGuard;
use Illuminate\Support\Facades\DB;

/** Changes a member's roles on behalf of an actor, with escalation checks and an audit entry. */
final readonly class ChangeMemberRoles
{
    public function __construct(
        private RoleAssignmentGuard $guard,
        private AddMember $addMember,
        private AuditLogger $audit,
    ) {}

    /** @param  list<string>  $roles */
    public function handle(User $actor, Membership $membership, array $roles): void
    {
        $organization = $membership->organization;
        $target = $membership->user;
        $before = $this->roleNames($target, $organization->id);
        $roles = array_values(array_unique($roles));

        $this->guard->authorizeRoleChange($actor, $organization, $target, $before, $roles);

        DB::transaction(function () use ($organization, $target, $membership, $roles): void {
            $this->addMember->handle($organization, $target, $roles, $membership->status);

            if (! $this->guard->isOwner($target, $organization)) {
                $this->guard->ensureAnotherOwnerRemains($organization, $target);
            }
        });

        sort($before);
        $after = $roles;
        sort($after);
        if ($before !== $after) {
            $this->audit->record('member.roles_changed', $membership, ['before' => $before, 'after' => $after], $actor);
        }
    }

    /** @return list<string> */
    private function roleNames(User $user, int $organizationId): array
    {
        return array_values(DB::table('model_has_roles as mhr')->join('roles', 'roles.id', '=', 'mhr.role_id')
            ->where('mhr.model_type', $user->getMorphClass())->where('mhr.model_id', $user->id)
            ->where('mhr.organization_id', $organizationId)->pluck('roles.name')
            ->map(fn (mixed $n): string => (string) $n)->all());
    }
}
