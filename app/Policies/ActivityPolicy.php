<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Audit\Models\Activity;
use App\Domain\Identity\Enums\Permission;
use App\Domain\Identity\Models\User;

/** Audit entries are read-only and visible to holders of audit.view in their organization. */
final class ActivityPolicy extends TenantPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->allows($user, Permission::AuditView);
    }

    public function view(User $user, Activity $activity): bool
    {
        return $this->allowsFor($user, $activity, Permission::AuditView);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, Activity $activity): bool
    {
        return false;
    }

    public function delete(User $user, Activity $activity): bool
    {
        return false;
    }
}
