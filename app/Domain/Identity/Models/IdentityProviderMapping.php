<?php

declare(strict_types=1);

namespace App\Domain\Identity\Models;

use App\Domain\Identity\Tenancy\BelongsToOrganization;
use Database\Factories\IdentityProviderMappingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Maps a claim value (e.g. an Entra group id in `groups`) to a role XOR a group (CHECK constraint). Mapping
 * targets are an IdP-managed set re-synced on every login; Owner is never a target (ADR 0036).
 *
 * @property int $id
 * @property int $organization_id
 * @property int $identity_provider_id
 * @property string $claim
 * @property string $value
 * @property int|null $role_id
 * @property int|null $group_id
 * @property-read IdentityProvider $provider
 * @property-read Role|null $role
 * @property-read Group|null $group
 */
#[UseFactory(IdentityProviderMappingFactory::class)]
#[Fillable(['identity_provider_id', 'claim', 'value', 'role_id', 'group_id'])]
class IdentityProviderMapping extends Model
{
    use BelongsToOrganization;

    /** @use HasFactory<IdentityProviderMappingFactory> */
    use HasFactory;

    use LogsActivity;

    protected $attributes = ['role_id' => null, 'group_id' => null];

    /** @return BelongsTo<IdentityProvider, $this> */
    public function provider(): BelongsTo
    {
        return $this->belongsTo(IdentityProvider::class, 'identity_provider_id');
    }

    /** @return BelongsTo<Role, $this> */
    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    /** @return BelongsTo<Group, $this> */
    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['identity_provider_id', 'claim', 'value', 'role_id', 'group_id'])
            ->logOnlyDirty()->dontLogEmptyChanges()->useLogName('audit');
    }
}
