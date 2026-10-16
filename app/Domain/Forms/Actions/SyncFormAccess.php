<?php

declare(strict_types=1);

namespace App\Domain\Forms\Actions;

use App\Domain\Audit\AuditLogger;
use App\Domain\Forms\Models\Form;
use App\Domain\Forms\Models\FormAccess;
use App\Domain\Identity\Models\Group;
use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;
use Illuminate\Support\Facades\DB;

/** Replaces who may fill a form in the Field App: groups and roles of the same organization. */
final readonly class SyncFormAccess
{
    public function __construct(private AuditLogger $audit) {}

    /**
     * @param  list<int>  $groupIds
     * @param  list<string>  $roleNames
     */
    public function handle(Form $form, array $groupIds, array $roleNames, User $actor): void
    {
        $groups = array_values(Group::query()->whereKey($groupIds)->pluck('id')->map(fn (mixed $id): int => (int) $id)->all());
        $roles = array_values(Role::query()->where('organization_id', $form->organization_id)->whereIn('name', $roleNames)
            ->pluck('name')->map(fn (mixed $n): string => (string) $n)->all());
        sort($groups);
        sort($roles);
        $before = $this->current($form);

        DB::transaction(function () use ($form, $groups, $roles): void {
            FormAccess::query()->where('form_id', $form->id)->delete();
            foreach ($groups as $groupId) {
                FormAccess::query()->create(['form_id' => $form->id, 'group_id' => $groupId]);
            }
            foreach ($roles as $role) {
                FormAccess::query()->create(['form_id' => $form->id, 'role_name' => $role]);
            }
        });

        $after = ['groups' => $groups, 'roles' => $roles];
        if ($before !== $after) {
            $this->audit->record('form.access_changed', $form, ['before' => $before, 'after' => $after], $actor);
        }
    }

    /** @return array{groups: list<int>, roles: list<string>} */
    private function current(Form $form): array
    {
        $grants = FormAccess::query()->where('form_id', $form->id)->get();
        $groups = array_values($grants->whereNotNull('group_id')->pluck('group_id')->map(fn (mixed $id): int => (int) $id)->sort()->values()->all());
        $roles = array_values($grants->whereNotNull('role_name')->pluck('role_name')->map(fn (mixed $n): string => (string) $n)->sort()->values()->all());

        return ['groups' => $groups, 'roles' => $roles];
    }
}
