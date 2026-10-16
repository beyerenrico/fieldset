<?php

declare(strict_types=1);

namespace App\Domain\Workflows\Support;

use App\Domain\Identity\Enums\MembershipStatus;
use App\Domain\Identity\Enums\SystemRole;
use App\Domain\Identity\Enums\UserStatus;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Tenancy\TenantContext;
use App\Domain\Submissions\Models\Submission;
use App\Domain\Workflows\Models\Task;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Resolves workflow recipients to active members of the current organization: users, group members,
 * role holders, task assignees, the submitter and the organization's Owners/Admins.
 */
final readonly class WorkflowRecipients
{
    public function __construct(private TenantContext $tenant) {}

    /**
     * @param  list<int>  $userIds
     * @param  list<int>  $groupIds
     * @param  list<string>  $roles  role names of this organization (system role values or custom names)
     * @return Collection<int, User>
     */
    public function users(array $userIds = [], array $groupIds = [], array $roles = []): Collection
    {
        if ($userIds === [] && $groupIds === [] && $roles === []) {
            return new Collection;
        }
        $orgId = $this->tenant->require();

        return $this->activeMembers()->where(function (Builder $q) use ($userIds, $groupIds, $roles, $orgId): void {
            $q->whereIn('users.id', $userIds === [] ? [0] : $userIds)
                ->orWhereIn('users.id', fn ($sub) => $sub->select('user_id')->from('group_user')
                    ->where('organization_id', $orgId)->whereIn('group_id', $groupIds === [] ? [0] : $groupIds))
                ->orWhereIn('users.id', $this->roleHolders($roles === [] ? ['-'] : $roles));
        })->orderBy('users.id')->get();
    }

    /** @return Collection<int, User> */
    public function assignees(Task $task): Collection
    {
        return $this->users(
            $task->assignee_user_id === null ? [] : [$task->assignee_user_id],
            $task->assignee_group_id === null ? [] : [$task->assignee_group_id],
        );
    }

    /** @return Collection<int, User> the user who submitted (else created) the submission, if still active */
    public function submitter(Submission $submission): Collection
    {
        $id = $submission->submitted_by ?? $submission->created_by;

        return $id === null ? new Collection : $this->users([$id]);
    }

    /** @return Collection<int, User> */
    public function administrators(): Collection
    {
        return $this->users(roles: [SystemRole::Owner->value, SystemRole::Admin->value]);
    }

    /** @return Builder<User> */
    private function activeMembers(): Builder
    {
        $orgId = $this->tenant->require();

        return User::query()->where('users.status', UserStatus::Active->value)
            ->whereIn('users.id', fn ($sub) => $sub->select('user_id')->from('organization_user')
                ->where('organization_id', $orgId)->where('status', MembershipStatus::Active->value));
    }

    /** @param  list<string>  $roles */
    private function roleHolders(array $roles): \Illuminate\Database\Query\Builder
    {
        $tables = config('permission.table_names');

        return DB::table($tables['model_has_roles'].' as mhr')
            ->join($tables['roles'].' as r', 'r.id', '=', 'mhr.role_id')
            ->where('mhr.model_type', (new User)->getMorphClass())
            ->where('mhr.organization_id', $this->tenant->require())
            ->whereIn('r.name', $roles)
            ->select('mhr.model_id');
    }
}
