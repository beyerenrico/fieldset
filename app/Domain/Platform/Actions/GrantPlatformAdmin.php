<?php

declare(strict_types=1);

namespace App\Domain\Platform\Actions;

use App\Domain\Audit\AuditLogger;
use App\Domain\Identity\Models\User;
use App\Domain\Platform\Models\PlatformAdmin;

/** Grants the platform operator role (ADR 0041); audited as `platform.admin_granted`. Idempotent. */
final readonly class GrantPlatformAdmin
{
    public function __construct(private AuditLogger $audit) {}

    /** @return bool whether a new grant was created */
    public function handle(User $user, ?User $grantedBy = null): bool
    {
        $grant = PlatformAdmin::query()->firstOrCreate(['user_id' => $user->id], ['granted_by' => $grantedBy?->id]);
        if (! $grant->wasRecentlyCreated) {
            return false;
        }

        $this->audit->record('platform.admin_granted', $user, ['user_id' => $user->id, 'email' => $user->email], $grantedBy);

        return true;
    }
}
