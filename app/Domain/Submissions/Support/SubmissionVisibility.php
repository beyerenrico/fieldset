<?php

declare(strict_types=1);

namespace App\Domain\Submissions\Support;

use App\Domain\Identity\Enums\Permission;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Support\OrganizationPermissions;
use App\Domain\Identity\Tenancy\TenantContext;
use App\Domain\Submissions\Models\Submission;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Which submissions a user may see (BUILD_PROMPT §8.6 "View all submissions" row): all
 * (view_all), forms they created (designer), submissions of members of their sharing groups
 * (reviewer "assigned/group", worker "+group if granted"), and their own.
 */
final readonly class SubmissionVisibility
{
    public function __construct(private OrganizationPermissions $permissions, private TenantContext $tenant) {}

    /**
     * @param  Builder<Submission>  $query
     * @return Builder<Submission>
     */
    public function apply(Builder $query, User $user): Builder
    {
        $orgId = $this->tenant->require();
        $can = fn (Permission $p): bool => $this->permissions->has($user, $orgId, $p);

        if ($can(Permission::SubmissionsViewAll)) {
            return $query;
        }

        $peerIds = $this->groupPeers($user, $orgId);

        return $query->where(function (Builder $q) use ($user, $can, $peerIds): void {
            $q->whereRaw('false');
            if ($can(Permission::SubmissionsViewOwn)) {
                $q->orWhere('created_by', $user->id);
            }
            if ($can(Permission::SubmissionsViewOwnForms)) {
                $q->orWhereIn('form_id', fn ($sub) => $sub->select('id')->from('forms')->where('created_by', $user->id));
            }
            if ($peerIds !== [] && ($can(Permission::SubmissionsViewOwn) || $can(Permission::SubmissionsViewAssigned))) {
                $q->orWhereIn('created_by', $peerIds);
            }
        });
    }

    public function canView(User $user, Submission $submission): bool
    {
        return $this->apply(Submission::query()->whereKey($submission->uuid), $user)->exists();
    }

    /** @return list<int> members of the user's groups that share submissions */
    private function groupPeers(User $user, int $orgId): array
    {
        return array_values(DB::table('group_user as mine')
            ->join('groups', 'groups.id', '=', 'mine.group_id')
            ->join('group_user as peers', 'peers.group_id', '=', 'mine.group_id')
            ->where('mine.user_id', $user->id)->where('mine.organization_id', $orgId)->where('groups.share_submissions', true)
            ->pluck('peers.user_id')->map(fn (mixed $id): int => (int) $id)->unique()->all());
    }
}
