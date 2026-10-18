<?php

declare(strict_types=1);

namespace App\Domain\Platform\Instance;

use App\Domain\Identity\Enums\MembershipStatus;
use App\Domain\Identity\Models\IdentityProvider;
use App\Domain\Identity\Models\LoginLink;
use App\Domain\Identity\Models\Membership;
use App\Domain\Identity\Sso\Enums\IdentityProviderType;
use App\Domain\Mail\Models\DocumentLink;
use App\Domain\Privacy\Models\DataExport;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * What follows the instance URL when an operator changes it with `fsa config set` (R21, ADR 0077), across all
 * organizations (platform scope):
 *
 * - organizations with an SSO provider: the SAML SP entity ID and ACS URL, or the OIDC redirect URI, are built from
 *   the URL (IdentityProvider::samlEntityId(), samlAcsUrl(), callbackUrl()), so their admins must register the new
 *   ones at the identity provider. Disabled providers and deleted organizations count too: re-enabling or restoring
 *   them would fail the same way;
 * - links already sent that carry the old address and stop working: pending invitations (signed, 7 days), document
 *   links in mails, unused sign-in links and downloadable data exports.
 *
 * Read-only; names and counts only, never a token, URL or secret.
 */
final class UrlChangeImpact
{
    /** Days an invitation link is valid (MemberInvitation signs it for this long). */
    public const int INVITATION_DAYS = 7;

    /** @return array{sso_organizations: array<int, array<string, mixed>>, links: array<string, int>} */
    public function assess(): array
    {
        $now = Carbon::now();

        return [
            'sso_organizations' => IdentityProvider::query()->withoutTenant()->with(['organization' => static fn (BelongsTo $query) => $query->withTrashed()])->orderBy('organization_id')->get()
                ->map(static fn (IdentityProvider $provider): array => [
                    'organization' => ['id' => $provider->organization->id, 'name' => $provider->organization->name, 'slug' => $provider->organization->slug],
                    'provider' => ['type' => $provider->type->value, 'name' => $provider->name, 'status' => $provider->status->value],
                    'follows_url' => $provider->type === IdentityProviderType::Saml ? ['entity_id', 'acs_url'] : ['redirect_uri'],
                ])->values()->all(),
            'links' => [
                'invitations' => Membership::query()->where('status', MembershipStatus::Invited)->where('invited_at', '>', $now->copy()->subDays(self::INVITATION_DAYS))->count(),
                'document_links' => DocumentLink::query()->withoutTenant()->where('expires_at', '>', $now)->count(),
                'login_links' => LoginLink::query()->whereNull('used_at')->where('expires_at', '>', $now)->count(),
                'data_exports' => DataExport::query()->withoutTenant()->whereNotNull('expires_at')->where('expires_at', '>', $now)->count(),
            ],
        ];
    }
}
