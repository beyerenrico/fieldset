<?php

declare(strict_types=1);

namespace App\Domain\Identity\Sso\Admin;

use App\Domain\Identity\Models\ExternalIdentity;
use App\Domain\Identity\Models\IdentityProvider;
use App\Domain\Identity\Models\SsoDomain;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Sessions\EndUserAccess;
use Illuminate\Database\Eloquent\Builder;

/**
 * When enforcement is switched on (SetSsoEnforcement), password sign-ins of the enforced domains must not
 * live on: the sessions and remember-me tokens (EndUserAccess::endSessions) of every user whose email is at
 * one of the organization's verified domains end, except the break-glass Owner and users already linked to
 * this provider (they sign in with SSO). Enforcement follows the email domain, so this applies to users of
 * other organizations with such an address too.
 */
final readonly class EndPasswordSessions
{
    /** @return int the number of users whose sessions were ended */
    public function handle(IdentityProvider $provider): int
    {
        $domains = SsoDomain::query()->withoutTenant()->where('organization_id', $provider->organization_id)
            ->whereNotNull('verified_at')->pluck('domain')->all();
        if ($domains === []) {
            return 0;
        }

        $users = User::query()
            ->where(static function (Builder $query) use ($domains): void {
                foreach ($domains as $domain) {
                    $query->orWhereRaw('lower(email) like ?', ['%@'.addcslashes(strtolower((string) $domain), '%_\\')]);
                }
            })
            ->when($provider->break_glass_user_id !== null, fn (Builder $q) => $q->whereKeyNot($provider->break_glass_user_id))
            ->whereNotIn('id', ExternalIdentity::query()->withoutTenant()->where('identity_provider_id', $provider->id)->select('user_id'))
            ->orderBy('id')
            ->get();

        foreach ($users as $user) {
            EndUserAccess::endSessions($user);
        }

        return $users->count();
    }
}
