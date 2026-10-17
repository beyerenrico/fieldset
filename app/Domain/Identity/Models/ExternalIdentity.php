<?php

declare(strict_types=1);

namespace App\Domain\Identity\Models;

use App\Domain\Identity\Tenancy\BelongsToOrganization;
use Database\Factories\ExternalIdentityFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A user's account at an organization's identity provider, unique per (provider, subject). Keyed by provider,
 * not issuer: an organization controls its own IdP's discovery document and could publish another
 * organization's issuer (ADR 0036). Not audited by model events: linking is the explicit
 * `sso.identity_linked` event, and every login would otherwise log `last_login_at`.
 *
 * @property int $id
 * @property int $organization_id
 * @property int $identity_provider_id
 * @property int $user_id
 * @property string $subject
 * @property string $issuer
 * @property string|null $last_email
 * @property Carbon|null $last_login_at
 * @property-read IdentityProvider $provider
 * @property-read User $user
 */
#[UseFactory(ExternalIdentityFactory::class)]
#[Fillable(['identity_provider_id', 'user_id', 'subject', 'issuer', 'last_email', 'last_login_at'])]
class ExternalIdentity extends Model
{
    use BelongsToOrganization;

    /** @use HasFactory<ExternalIdentityFactory> */
    use HasFactory;

    protected $attributes = ['last_email' => null, 'last_login_at' => null];

    protected function casts(): array
    {
        return ['last_login_at' => 'datetime'];
    }

    /** @return BelongsTo<IdentityProvider, $this> */
    public function provider(): BelongsTo
    {
        return $this->belongsTo(IdentityProvider::class, 'identity_provider_id');
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
