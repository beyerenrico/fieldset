<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Documents\Models\DesignVersion;
use App\Domain\Identity\Enums\Permission;
use App\Domain\Identity\Models\User;

final class DesignVersionPolicy extends TenantPolicy
{
    public function view(User $user, DesignVersion $version): bool
    {
        return $this->allowsFor($user, $version, Permission::FormsManage);
    }

    public function retire(User $user, DesignVersion $version): bool
    {
        return $this->allowsFor($user, $version, Permission::FormsManage);
    }

    public function update(User $user, DesignVersion $version): bool
    {
        return false;
    }

    public function delete(User $user, DesignVersion $version): bool
    {
        return false;
    }
}
