<?php

declare(strict_types=1);

namespace App\Domain\Identity\Sso\Login;

use App\Domain\Audit\AuditLogger;
use App\Domain\Identity\Actions\SyncMemberGroups;
use App\Domain\Identity\Models\IdentityProvider;
use App\Domain\Identity\Models\IdentityProviderMapping;
use App\Domain\Identity\Models\Membership;
use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Sso\Admin\SsoTargets;
use App\Domain\Identity\Sso\Data\ExternalProfile;
use App\Domain\Identity\Support\OrganizationPermissions;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Identity resolution step 5 of ADR 0036 (inside the provider's tenant): roles and groups that are mapping
 * targets form an IdP-managed set re-synced on every login — a target is held exactly while a claim value
 * maps to it. Manual assignments (non-targets) and Owner-level roles (Owner, or any role with
 * organization.delete — SsoTargets::isOwnerLevel, the rule the admin side validates with) are never granted
 * or removed, even when a stale or hand-made mapping targets them. A claim that a mapping reads but
 * the login lacks is audited (`sso.mapping_claim_missing`):
 * - the provider's groups claim (`groups_claim` / `groups_attribute`, alias `groups`) counts as empty, because
 *   IdPs omit an empty multi-valued claim (Keycloak's groups mapper, SAML attributes) and keeping roles of
 *   groups the user has left would retain privileges (`treated_as: empty`);
 * - any other claim leaves its targets as they are (`treated_as: skipped`).
 * Changes are audited once as member.roles_changed / member.groups_changed with `source: sso`.
 */
final readonly class SyncClaimMappings
{
    public function __construct(
        private SyncMemberGroups $syncGroups,
        private OrganizationPermissions $permissions,
        private SsoTargets $targets,
        private AuditLogger $audit,
    ) {}

    public function handle(IdentityProvider $provider, User $user, ExternalProfile $profile): void
    {
        $mappings = IdentityProviderMapping::query()->where('identity_provider_id', $provider->id)->orderBy('id')->get();
        if ($mappings->isEmpty()) {
            return;
        }
        $membership = Membership::query()->where('organization_id', $provider->organization_id)->where('user_id', $user->id)->firstOrFail();

        [$claims, $missing] = $this->claims($provider, $membership, $mappings, $profile);
        $undecided = $mappings->filter(static fn (IdentityProviderMapping $m): bool => in_array($m->claim, $missing, true));
        $present = $mappings->reject(static fn (IdentityProviderMapping $m): bool => in_array($m->claim, $missing, true));
        $wanted = $present->filter(static fn (IdentityProviderMapping $m): bool => in_array($m->value, ClaimValues::list($claims[$m->claim] ?? null), true));

        $this->syncRoles($provider, $user, $membership, $this->targets($present, $undecided, 'role_id'), $this->ids($wanted, 'role_id'));
        $this->syncMemberGroups($provider, $user, $membership, $this->targets($present, $undecided, 'group_id'), $this->ids($wanted, 'group_id'));
    }

    /**
     * The login's claims with an absent groups claim as empty, and the other absent claims (both audited).
     *
     * @param  Collection<int, IdentityProviderMapping>  $mappings
     * @return array{0: array<string, mixed>, 1: list<string>}
     */
    private function claims(IdentityProvider $provider, Membership $membership, Collection $mappings, ExternalProfile $profile): array
    {
        $claims = $profile->claims;
        $groupsClaims = [ClaimValues::groupsClaim($provider), 'groups'];
        $missing = [];
        foreach ($mappings->pluck('claim')->unique() as $claim) {
            if (! is_string($claim) || array_key_exists($claim, $claims)) {
                continue;
            }
            $empty = in_array($claim, $groupsClaims, true);
            if ($empty) {
                $claims[$claim] = [];
            } else {
                $missing[] = $claim;
            }
            $this->audit->record('sso.mapping_claim_missing', $membership, [
                'provider' => $provider->uuid, 'claim' => $claim, 'treated_as' => $empty ? 'empty' : 'skipped',
            ]);
        }

        return [$claims, $missing];
    }

    /**
     * @param  list<int>  $managed
     * @param  list<int>  $wanted
     */
    private function syncRoles(IdentityProvider $provider, User $user, Membership $membership, array $managed, array $wanted): void
    {
        $managed = $this->withoutOwnerLevel($provider, $managed);
        $current = array_values(DB::table('model_has_roles')->where('model_type', $user->getMorphClass())->where('model_id', $user->id)
            ->where('organization_id', $provider->organization_id)->pluck('role_id')->map(static fn (mixed $id): int => (int) $id)->all());
        $next = $this->next($current, $managed, $wanted);
        if ($next === null) {
            return;
        }

        $before = $this->roleNames($provider, $current);
        $user->unsetRelation('roles')->unsetRelation('permissions');
        $user->syncRoles(Role::query()->where('organization_id', $provider->organization_id)->whereKey($next)->get());
        $user->unsetRelation('roles');
        $this->permissions->forget();

        $this->audit->record('member.roles_changed', $membership, [
            'before' => $before, 'after' => $this->roleNames($provider, $next), 'source' => 'sso', 'provider' => $provider->uuid,
        ]);
    }

    /**
     * @param  list<int>  $roleIds
     * @return list<int>
     */
    private function withoutOwnerLevel(IdentityProvider $provider, array $roleIds): array
    {
        $ownerLevel = Role::query()->where('organization_id', $provider->organization_id)->whereKey($roleIds)->get()
            ->filter(fn (Role $role): bool => $this->targets->isOwnerLevel($role))
            ->map(static fn (Role $role): int => (int) $role->id)->all();

        return array_values(array_diff($roleIds, $ownerLevel));
    }

    /**
     * @param  list<int>  $managed
     * @param  list<int>  $wanted
     */
    private function syncMemberGroups(IdentityProvider $provider, User $user, Membership $membership, array $managed, array $wanted): void
    {
        $current = array_values(DB::table('group_user')->where('organization_id', $provider->organization_id)->where('user_id', $user->id)
            ->pluck('group_id')->map(static fn (mixed $id): int => (int) $id)->all());
        $next = $this->next($current, $managed, $wanted);
        if ($next === null) {
            return;
        }

        $this->syncGroups->handle($provider->organization, $user, $next);
        sort($current);
        $this->audit->record('member.groups_changed', $membership, [
            'before' => $current, 'after' => $next, 'source' => 'sso', 'provider' => $provider->uuid,
        ]);
    }

    /**
     * The new assignment, or null when nothing changes.
     *
     * @param  list<int>  $current
     * @param  list<int>  $managed
     * @param  list<int>  $wanted
     * @return list<int>|null
     */
    private function next(array $current, array $managed, array $wanted): ?array
    {
        $next = array_values(array_unique([...array_diff($current, $managed), ...array_intersect($wanted, $managed)]));
        sort($next);
        $sortedCurrent = array_values(array_unique($current));
        sort($sortedCurrent);

        return $next === $sortedCurrent ? null : $next;
    }

    /**
     * Targets of mappings whose claim arrived, minus targets that a mapping on a missing claim also feeds.
     *
     * @param  Collection<int, IdentityProviderMapping>  $present
     * @param  Collection<int, IdentityProviderMapping>  $undecided
     * @return list<int>
     */
    private function targets(Collection $present, Collection $undecided, string $column): array
    {
        return array_values(array_diff($this->ids($present, $column), $this->ids($undecided, $column)));
    }

    /**
     * @param  Collection<int, IdentityProviderMapping>  $mappings
     * @return list<int>
     */
    private function ids(Collection $mappings, string $column): array
    {
        return array_values(array_unique($mappings->pluck($column)->filter()->map(static fn (mixed $id): int => (int) $id)->all()));
    }

    /**
     * @param  list<int>  $ids
     * @return list<string>
     */
    private function roleNames(IdentityProvider $provider, array $ids): array
    {
        return array_values(Role::query()->where('organization_id', $provider->organization_id)->whereKey($ids)->orderBy('name')
            ->pluck('name')->map(static fn (mixed $name): string => (string) $name)->all());
    }
}
