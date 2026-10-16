<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Forms\Models\FormVersion;
use App\Domain\Identity\Enums\Permission;
use App\Domain\Identity\Models\User;

final class FormVersionPolicy extends TenantPolicy
{
    public function view(User $user, FormVersion $version): bool
    {
        return $this->allowsFor($user, $version, Permission::FormsManage);
    }

    public function retire(User $user, FormVersion $version): bool
    {
        return $this->allowsFor($user, $version, Permission::FormsManage);
    }

    public function update(User $user, FormVersion $version): bool
    {
        return false;
    }

    public function delete(User $user, FormVersion $version): bool
    {
        return false;
    }
}
