<?php

declare(strict_types=1);

namespace App\Domain\Identity\Actions;

use App\Domain\Audit\AuditLogger;
use App\Domain\Identity\Models\Group;
use App\Domain\Identity\Models\Membership;
use App\Domain\Identity\Models\Organization;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/** Replaces a user's group memberships within one organization (takes effect immediately). */
final readonly class SyncMemberGroups
{
    public function __construct(private TenantContext $tenant, private AuditLogger $audit) {}

    /** @param  list<int>  $groupIds */
    public function handle(Organization $organization, User $user, array $groupIds, ?User $actor = null): void
    {
        [$before, $after] = $this->tenant->run($organization, function () use ($organization, $user, $groupIds): array {
            $before = $this->current($organization, $user);
            // Only groups of this organization are accepted (scope applies).
            $valid = array_values(Group::query()->whereKey($groupIds)->orderBy('id')->pluck('id')
                ->map(fn (mixed $id): int => (int) $id)->all());

            DB::table('group_user')->where('organization_id', $organization->id)->where('user_id', $user->id)
                ->whereNotIn('group_id', $valid)->delete();

            foreach ($valid as $groupId) {
                DB::table('group_user')->updateOrInsert(
                    ['group_id' => $groupId, 'user_id' => $user->id],
                    ['organization_id' => $organization->id, 'updated_at' => now(), 'created_at' => now()],
                );
            }

            return [$before, $valid];
        });

        if ($before !== $after && $actor instanceof User) {
            $membership = Membership::query()->where('organization_id', $organization->id)->where('user_id', $user->id)->first();
            $this->audit->record('member.groups_changed', $membership ?? $user, ['before' => $before, 'after' => $after], $actor);
        }
    }

    /** @return list<int> */
    private function current(Organization $organization, User $user): array
    {
        return array_values(DB::table('group_user')->where('organization_id', $organization->id)->where('user_id', $user->id)
            ->orderBy('group_id')->pluck('group_id')->map(fn (mixed $id): int => (int) $id)->all());
    }
}
