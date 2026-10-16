<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Forms\Models\FormAccess;
use App\Domain\Identity\Enums\Permission;
use App\Domain\Identity\Models\User;

final class FormAccessPolicy extends TenantPolicy
{
    public function view(User $user, FormAccess $grant): bool
    {
        return $this->allowsFor($user, $grant, Permission::FormsManage);
    }

    public function update(User $user, FormAccess $grant): bool
    {
        return $this->allowsFor($user, $grant, Permission::FormsManage);
    }
}
