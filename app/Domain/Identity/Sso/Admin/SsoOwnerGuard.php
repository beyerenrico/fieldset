<?php

declare(strict_types=1);

namespace App\Domain\Identity\Sso\Admin;

use App\Domain\Identity\Enums\Permission;
use App\Domain\Identity\Models\Organization;
use App\Domain\Identity\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * The Owner-level rule of the SSO settings (gate review M7): the connection, the identity-deciding
 * attribute names, the trust in unverified email addresses, the JIT default role and switching enforcement
 * off need an active user with an active membership holding organization.delete in the organization: the
 * same rule as the break-glass Owner (BreakGlassOwners) and the Owner-level roles of SsoTargets. Admins
 * keep everything else (domains, mappings within their own roles, name, scopes, secret rotation).
 */
final readonly class SsoOwnerGuard
{
    public function isOwnerLevel(User $actor, Organization|int $org): bool
    {
        $orgId = $org instanceof Organization ? $org->id : $org;

        return PermissionHolders::query($orgId, Permission::OrganizationDelete)->whereKey($actor->id)->exists();
    }

    /**
     * Refuses a change of Owner-only provider settings by anyone below Owner level. Error keys are the input
     * keys of the changed settings.
     *
     * @param  list<string>  $changed  fingerprint keys of SaveIdentityProvider (`type`, `config.issuer`, …)
     *
     * @throws ValidationException
     */
    public function assertMayChange(array $changed, User $actor, Organization|int $org): void
    {
        $restricted = array_values(array_intersect($changed, ProviderSettings::ownerOnlyFingerprintKeys()));
        if ($restricted === [] || $this->isOwnerLevel($actor, $org)) {
            return;
        }

        $messages = [];
        foreach ($restricted as $key) {
            $messages[str_starts_with($key, 'config.') ? substr($key, 7) : $key] = __('sso_admin.provider.errors.owner_only');
        }

        throw ValidationException::withMessages($messages);
    }
}
