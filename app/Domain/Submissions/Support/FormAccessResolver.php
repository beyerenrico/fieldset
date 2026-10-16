<?php

declare(strict_types=1);

namespace App\Domain\Submissions\Support;

use App\Domain\Forms\Enums\FormStatus;
use App\Domain\Forms\Models\Form;
use App\Domain\Forms\Models\FormAccess;
use App\Domain\Identity\Enums\Permission;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Support\OrganizationPermissions;
use App\Domain\Identity\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Who may fill which form (BUILD_PROMPT §8.6): Owner/Admin (forms.fill_all) every form; everyone
 * else with forms.fill needs a grant through one of their groups or roles. Evaluated on every API call.
 */
final readonly class FormAccessResolver
{
    public function __construct(private OrganizationPermissions $permissions, private TenantContext $tenant) {}

    public function canFill(User $user, Form $form): bool
    {
        return $this->fillable($user)->whereKey($form->id)->exists();
    }

    /** @return Builder<Form> active forms the user may fill in the current organization */
    public function fillable(User $user): Builder
    {
        $orgId = $this->tenant->require();
        $query = Form::query()
            ->where('status', FormStatus::Active->value)
            ->whereNotNull('current_version_id')
            ->whereHas('currentVersion', fn (Builder $q) => $q->whereNull('retired_at'));

        if (! $this->permissions->has($user, $orgId, Permission::FormsFill)) {
            return $query->whereRaw('false');
        }

        if ($this->permissions->has($user, $orgId, Permission::FormsFillAll)) {
            return $query;
        }

        $groupIds = DB::table('group_user')->where('organization_id', $orgId)->where('user_id', $user->id)->pluck('group_id')->all();
        $roles = DB::table('model_has_roles')->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->where('model_has_roles.model_type', $user->getMorphClass())->where('model_has_roles.model_id', $user->id)
            ->where('model_has_roles.organization_id', $orgId)->pluck('roles.name')->all();

        return $query->whereIn('id', FormAccess::query()->select('form_id')->where(function ($q) use ($groupIds, $roles): void {
            $q->whereIn('group_id', $groupIds)->orWhereIn('role_name', $roles);
        }));
    }
}
