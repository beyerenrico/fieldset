<?php

declare(strict_types=1);

namespace App\Domain\Platform\Actions;

use App\Domain\Audit\AuditLogger;
use App\Domain\Identity\Models\User;
use App\Domain\Platform\Models\PlatformAdmin;

/**
 * Revokes the platform operator role (ADR 0041); audited as `platform.admin_revoked`. A signed-in operator
 * loses /platform access on the next request (PlatformAdmins is checked on every request).
 */
final readonly class RevokePlatformAdmin
{
    public function __construct(private AuditLogger $audit) {}

    /** @return bool whether a grant existed */
    public function handle(User $user, ?User $revokedBy = null): bool
    {
        $deleted = PlatformAdmin::query()->where('user_id', $user->id)->delete();
        if ($deleted === 0) {
            return false;
        }

        $this->audit->record('platform.admin_revoked', $user, ['user_id' => $user->id, 'email' => $user->email], $revokedBy);

        return true;
    }
}
