<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Forms\Models\Form;
use App\Domain\Identity\Enums\Permission;
use App\Domain\Identity\Models\User;

/** Building and publishing forms needs forms.manage in the form's organization (§8.6). */
final class FormPolicy extends TenantPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->allows($user, Permission::FormsManage);
    }

    public function view(User $user, Form $form): bool
    {
        return $this->allowsFor($user, $form, Permission::FormsManage);
    }

    public function create(User $user): bool
    {
        return $this->allows($user, Permission::FormsManage);
    }

    public function update(User $user, Form $form): bool
    {
        return $this->allowsFor($user, $form, Permission::FormsManage);
    }

    public function publish(User $user, Form $form): bool
    {
        return $this->update($user, $form);
    }

    public function delete(User $user, Form $form): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
