<?php

declare(strict_types=1);

namespace App\Domain\Privacy\Erasure;

use App\Domain\Audit\AuditLogger;
use App\Domain\Identity\Enums\Permission;
use App\Domain\Identity\Models\Membership;
use App\Domain\Identity\Models\Organization;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Support\RoleAssignmentGuard;
use App\Domain\Identity\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Erasure of a user account (GDPR Art. 17, ADR 0039). The account row stays so that records the
 * organization must keep (submissions, approvals, audit entries) stay attributed — to "Deleted user": name,
 * email, password, MFA, locale and time zone are replaced, sessions, API tokens, external (SSO) identities,
 * notifications, preferences and data exports are deleted, device names cleared and every device asked to
 * wipe, memberships deactivated with their roles and groups removed. Refused while the user is the last
 * Owner of an organization. One audit entry `privacy.user_erased`, without personal data.
 *
 * - Self-service: any user, for their own account.
 * - By an admin (members.manage): only accounts that belong to no other organization (the account is shared
 *   otherwise; the person can erase it themselves), and an Owner only by an Owner.
 */
final readonly class EraseUser
{
    public function __construct(
        private RoleAssignmentGuard $guard,
        private AuditLogger $audit,
        private TenantContext $tenant,
        private AccountScrubber $scrubber,
    ) {}

    public function self(User $user): void
    {
        DB::transaction(function () use ($user): void {
            foreach ($this->ownedOrganizations($user) as $organization) {
                $this->ensureAnotherOwner($organization, $user);
            }
            $this->erase($user, $user, true);
        });
    }

    public function byAdmin(User $actor, User $target): void
    {
        $organization = $this->tenant->organization() ?? throw new AuthorizationException;
        if (! $actor->isActive() || ! $actor->hasPermissionIn($organization, Permission::MembersManage)
            || ! Membership::query()->where('organization_id', $organization->id)->where('user_id', $target->id)->exists()) {
            throw new AuthorizationException;
        }
        if ($actor->is($target)) {
            throw ValidationException::withMessages(['erasure' => __('privacy.erasure.use_self_service')]);
        }
        // Any membership elsewhere, also a deactivated one: that organization's records point at the account too.
        $elsewhere = Membership::query()->where('user_id', $target->id)->where('organization_id', '!=', $organization->id)->exists();
        if ($elsewhere) {
            throw ValidationException::withMessages(['erasure' => __('privacy.erasure.other_organizations')]);
        }

        DB::transaction(function () use ($actor, $target, $organization): void {
            if ($this->guard->isOwner($target, $organization)) {
                if (! $this->guard->isOwner($actor, $organization)) {
                    throw ValidationException::withMessages(['erasure' => __('privacy.erasure.only_owner_erases_owner')]);
                }
                $this->ensureAnotherOwner($organization, $target);
            }
            $this->erase($target, $actor, false);
        });
    }

    public static function isErased(User $user): bool
    {
        return User::query()->whereKey($user->id)->whereNotNull('erased_at')->exists();
    }

    private function erase(User $user, User $actor, bool $selfService): void
    {
        if (self::isErased($user)) {
            return;
        }
        $this->scrubber->scrub($user);
        $this->audit->record('privacy.user_erased', $user, ['self_service' => $selfService], $actor);
    }

    /** @return list<Organization> */
    private function ownedOrganizations(User $user): array
    {
        return array_values($user->activeOrganizations()->get()
            ->filter(fn (Organization $org): bool => $this->guard->isOwner($user, $org))->all());
    }

    private function ensureAnotherOwner(Organization $organization, User $user): void
    {
        try {
            $this->guard->ensureAnotherOwnerRemains($organization, $user);
        } catch (ValidationException) {
            throw ValidationException::withMessages(['erasure' => __('privacy.erasure.last_owner', ['organization' => $organization->name])]);
        }
    }
}
