<?php

declare(strict_types=1);

namespace App\Domain\Identity\Sso\Domains;

use App\Domain\Identity\Models\ExternalIdentity;
use App\Domain\Identity\Models\IdentityProvider;
use App\Domain\Identity\Models\SsoDomain;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Sso\Login\EmailDomain;
use Illuminate\Support\Facades\DB;

/**
 * Whether a user's email address is managed by single sign-on, so the user must not change it themselves
 * (gate review M7: moving the address off an enforced domain would escape enforcement). It is when:
 * - the user has an external identity at any provider (the IdP keeps the address in sync; ADR 0036), or
 * - the address's domain is verified by an organization the user belongs to, or by one that enforces SSO
 *   (enforcement follows the email domain into every organization).
 * The address then changes only through the identity provider.
 */
final readonly class ManagedEmailAddress
{
    public function isManaged(User $user): bool
    {
        if (ExternalIdentity::query()->withoutTenant()->where('user_id', $user->id)->exists()) {
            return true;
        }

        $domain = EmailDomain::of($user->email);
        $claim = $domain === null ? null : SsoDomain::verifiedOwnerOf($domain);
        if (! $claim instanceof SsoDomain) {
            return false;
        }

        return DB::table('organization_user')->where('organization_id', $claim->organization_id)->where('user_id', $user->id)->exists()
            || IdentityProvider::query()->withoutTenant()->where('organization_id', $claim->organization_id)->where('enforce_sso', true)->exists();
    }
}
