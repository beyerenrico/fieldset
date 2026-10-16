<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Identity\Models\User;

/**
 * Users are platform-level accounts. Organization admins manage memberships (MembershipPolicy),
 * never the account itself; a user may view and update only their own account.
 */
final class UserPolicy
{
    public function view(User $actor, User $user): bool
    {
        return $actor->is($user);
    }

    public function update(User $actor, User $user): bool
    {
        return $actor->is($user) && $actor->isActive();
    }

    public function delete(): bool
    {
        return false;
    }
}
