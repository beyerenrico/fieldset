<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Documents\Models\Design;
use App\Domain\Identity\Enums\Permission;
use App\Domain\Identity\Models\User;

/** Designs are built and published with forms.manage (§8.6 "Build and publish forms, designs"). */
final class DesignPolicy extends TenantPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->allows($user, Permission::FormsManage);
    }

    public function view(User $user, Design $design): bool
    {
        return $this->allowsFor($user, $design, Permission::FormsManage);
    }

    public function create(User $user): bool
    {
        return $this->allows($user, Permission::FormsManage);
    }

    public function update(User $user, Design $design): bool
    {
        return $this->allowsFor($user, $design, Permission::FormsManage);
    }

    public function publish(User $user, Design $design): bool
    {
        return $this->update($user, $design);
    }

    public function delete(User $user, Design $design): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
