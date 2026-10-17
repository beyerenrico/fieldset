<?php

declare(strict_types=1);

namespace App\Domain\Platform;

use App\Domain\Identity\Models\User;
use App\Domain\Platform\Models\PlatformAdmin;

/**
 * Who is a platform operator (ADR 0041): an active user with a `platform_admins` row. Deliberately not
 * memoized: a revoked grant takes effect on the next check, also within a long-running worker.
 */
final class PlatformAdmins
{
    public function check(User $user): bool
    {
        return $user->isActive() && $this->granted($user);
    }

    public function granted(User $user): bool
    {
        return PlatformAdmin::query()->where('user_id', $user->id)->exists();
    }
}
