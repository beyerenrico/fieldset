<?php

declare(strict_types=1);

namespace App\Domain\Privacy\Policies;

use App\Domain\Identity\Enums\Permission;
use App\Domain\Identity\Models\User;
use App\Domain\Privacy\Models\LegalHold;
use App\Policies\TenantPolicy;

/** Legal holds are placed and released by organization managers (Owner, Admin); never deleted. */
final class LegalHoldPolicy extends TenantPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->allows($user, Permission::OrganizationManage);
    }

    public function view(User $user, LegalHold $hold): bool
    {
        return $this->allowsFor($user, $hold, Permission::OrganizationManage);
    }

    public function create(User $user): bool
    {
        return $this->allows($user, Permission::OrganizationManage);
    }

    /** Releasing is the only change. */
    public function update(User $user, LegalHold $hold): bool
    {
        return $hold->isActive() && $this->allowsFor($user, $hold, Permission::OrganizationManage);
    }

    public function delete(User $user, LegalHold $hold): bool
    {
        return false;
    }
}
