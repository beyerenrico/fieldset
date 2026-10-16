<?php

declare(strict_types=1);

namespace App\Domain\Identity\Actions;

use App\Domain\Identity\Enums\MembershipStatus;
use App\Domain\Identity\Enums\SystemRole;
use App\Domain\Identity\Models\Membership;
use App\Domain\Identity\Models\Organization;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Support\OrganizationPermissions;
use App\Domain\Identity\Tenancy\TenantContext;

/** Adds (or re-activates) a user in an organization and assigns roles there. */
final readonly class AddMember
{
    public function __construct(
        private TenantContext $tenant,
        private OrganizationPermissions $permissions,
    ) {}

    /**
     * @param  list<SystemRole|string>  $roles
     */
    public function handle(Organization $organization, User $user, array $roles, MembershipStatus $status = MembershipStatus::Active): Membership
    {
        $membership = Membership::query()->updateOrCreate(
            ['organization_id' => $organization->id, 'user_id' => $user->id],
            ['status' => $status, 'joined_at' => $status === MembershipStatus::Active ? now() : null],
        );

        $this->tenant->run($organization, function () use ($user, $roles): void {
            $user->unsetRelation('roles')->unsetRelation('permissions');
            $user->syncRoles(array_map(
                static fn (SystemRole|string $r): string => $r instanceof SystemRole ? $r->value : $r,
                $roles,
            ));
            $user->unsetRelation('roles');
        });

        $this->permissions->forget();

        return $membership;
    }
}
