<?php

declare(strict_types=1);

namespace App\Domain\Identity\Actions;

use App\Domain\Identity\Models\Group;
use App\Domain\Identity\Models\Organization;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/** Replaces a user's group memberships within one organization (takes effect immediately). */
final class SyncMemberGroups
{
    public function __construct(private readonly TenantContext $tenant) {}

    /** @param  list<int>  $groupIds */
    public function handle(Organization $organization, User $user, array $groupIds): void
    {
        $this->tenant->run($organization, function () use ($organization, $user, $groupIds): void {
            // Only groups of this organization are accepted (scope applies).
            $valid = Group::query()->whereKey($groupIds)->pluck('id')->all();

            DB::table('group_user')->where('organization_id', $organization->id)->where('user_id', $user->id)
                ->whereNotIn('group_id', $valid)->delete();

            foreach ($valid as $groupId) {
                DB::table('group_user')->updateOrInsert(
                    ['group_id' => $groupId, 'user_id' => $user->id],
                    ['organization_id' => $organization->id, 'updated_at' => now(), 'created_at' => now()],
                );
            }
        });
    }
}
