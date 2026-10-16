<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Identity\Enums\Permission;
use App\Domain\Identity\Models\Membership;
use App\Domain\Identity\Models\User;

final class MembershipPolicy extends TenantPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->allows($user, Permission::MembersManage);
    }

    public function view(User $user, Membership $membership): bool
    {
        return $this->allowsFor($user, $membership, Permission::MembersManage);
    }

    public function create(User $user): bool
    {
        return $this->allows($user, Permission::MembersManage);
    }

    public function update(User $user, Membership $membership): bool
    {
        return $this->allowsFor($user, $membership, Permission::MembersManage);
    }

    /** Deactivating yourself would lock you out; Owners are protected by the action itself. */
    public function deactivate(User $user, Membership $membership): bool
    {
        return $membership->user_id !== $user->id && $this->update($user, $membership);
    }

    public function delete(User $user, Membership $membership): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
