<?php

declare(strict_types=1);

namespace App\Domain\Identity\Tokens;

use App\Domain\Identity\Enums\Permission;
use App\Domain\Identity\Models\Organization;
use App\Domain\Identity\Models\User;
use App\Domain\Platform\Impersonation\ImpersonationStore;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Who may manage integration tokens (ADR 0043):
 * - every active panel user of the organization (`admin.access`) creates, lists and revokes their own tokens;
 * - holders of `members.manage` (Owners, Admins) list and revoke every token of the organization;
 * - nobody creates a token while impersonating (an operator must not leave a credential behind, ADR 0041).
 */
final class ApiTokenAccess
{
    public function canUse(User $user, Organization|int $organization): bool
    {
        return $user->isActive() && $user->isMemberOf($organization)
            && $user->hasPermissionIn($organization, Permission::AdminAccess);
    }

    public function canCreate(User $user, Organization|int $organization): bool
    {
        return $this->canUse($user, $organization) && ! $this->impersonating();
    }

    public function canManageAll(User $user, Organization|int $organization): bool
    {
        return $this->canUse($user, $organization) && $user->hasPermissionIn($organization, Permission::MembersManage);
    }

    public function canRevoke(User $user, PersonalAccessToken $token): bool
    {
        $organizationId = $token->getAttribute('organization_id');
        if ($organizationId === null || ! $this->canUse($user, (int) $organizationId)) {
            return false;
        }

        return self::ownedBy($token, $user) || $this->canManageAll($user, (int) $organizationId);
    }

    public static function ownedBy(PersonalAccessToken $token, User $user): bool
    {
        return $token->getAttribute('tokenable_type') === $user->getMorphClass()
            && (int) $token->getAttribute('tokenable_id') === $user->id;
    }

    private function impersonating(): bool
    {
        return app(ImpersonationStore::class)->active();
    }
}
