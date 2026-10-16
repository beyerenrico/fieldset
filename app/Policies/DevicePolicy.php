<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Identity\Enums\Permission;
use App\Domain\Identity\Models\Device;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Tenancy\TenantContext;

/** A user sees their own devices; members.manage holders may revoke devices of their members. */
final class DevicePolicy
{
    public function view(User $user, Device $device): bool
    {
        return $device->user_id === $user->id || $this->managesOwner($user, $device);
    }

    public function revoke(User $user, Device $device): bool
    {
        return $this->view($user, $device);
    }

    private function managesOwner(User $user, Device $device): bool
    {
        $orgId = app(TenantContext::class)->id();

        return $orgId !== null && $user->hasPermissionIn($orgId, Permission::MembersManage) && $device->user->isMemberOf($orgId);
    }
}
