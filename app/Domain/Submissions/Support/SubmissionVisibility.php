<?php

declare(strict_types=1);

namespace App\Domain\Submissions\Support;

use App\Domain\Identity\Enums\Permission;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Support\OrganizationPermissions;
use App\Domain\Identity\Tenancy\TenantContext;
use App\Domain\Submissions\Models\Submission;
use App\Domain\Workflows\Enums\TaskStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Which submissions a user may see (BUILD_PROMPT §8.6 "View all submissions" row): all
 * (view_all), forms they created (designer), submissions of members of their sharing groups
 * (reviewer "assigned/group", worker "+group if granted"), open review tasks assigned to them or their groups
 * and tasks they decided (M4, `view_assigned` only), and their own.
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
        return $this->scope($query, $user, false);
    }

    public function canView(User $user, Submission $submission): bool
    {
        return $this->scope(Submission::query()->whereKey($submission->uuid), $user, true)->exists();
    }

    /**
     * The visibility rules as one WHERE clause. `$single` (one submission, canView) checks the task branch as a
     * correlated EXISTS, which reads the submission's own tasks through their index; lists keep the IN
     * subquery, which Postgres hashes once (and runs in parallel) instead of probing per row (M8-T05).
     *
     * @param  Builder<Submission>  $query
     * @return Builder<Submission>
     */
    private function scope(Builder $query, User $user, bool $single): Builder
    {
        $orgId = $this->tenant->require();
        $can = fn (Permission $p): bool => $this->permissions->has($user, $orgId, $p);

        if ($can(Permission::SubmissionsViewAll)) {
            return $query;
        }

        $peerIds = $this->groupPeers($user, $orgId);

        return $query->where(function (Builder $q) use ($user, $can, $peerIds, $orgId, $single): void {
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
            if ($can(Permission::SubmissionsViewAssigned)) {
                $this->assigned($q, $user, $orgId, $single);
            }
        });
    }

    /**
     * Review assignments (M4, ADR 0032 amending 0030), for `view_assigned` holders only: submissions with an
     * OPEN review task assigned to the user or one of their groups, and those whose review the user decided.
     * Assignment alone never grants visibility, and a closed or cancelled task stops granting it.
     *
     * @param  Builder<Submission>  $query
     */
    private function assigned(Builder $query, User $user, int $orgId, bool $single): void
    {
        $myGroups = fn ($g) => $g->select('group_id')->from('group_user')->where('user_id', $user->id)->where('organization_id', $orgId);
        $openForMe = fn ($t) => $t->where('status', TaskStatus::Open->value)
            ->where(fn ($mine) => $mine->where('assignee_user_id', $user->id)->orWhereIn('assignee_group_id', $myGroups));
        $relevant = fn ($t) => $t->where('tasks.organization_id', $orgId)->where(fn ($r) => $r->where($openForMe)->orWhere('completed_by', $user->id));

        if ($single) {
            $query->orWhereExists(fn ($sub) => $relevant($sub->selectRaw('1')->from('tasks')->whereColumn('tasks.submission_uuid', 'submissions.uuid')));

            return;
        }
        $query->orWhereIn('uuid', fn ($sub) => $relevant($sub->select('submission_uuid')->from('tasks')));
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
