<?php

declare(strict_types=1);

namespace App\Domain\Identity\Models;

use App\Domain\Identity\Enums\MembershipStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * A user's membership in an organization (`organization_user`). Not tenant-scoped: a user's
 * memberships are listed across organizations (tenant switcher, `GET /me`). Audited explicitly by
 * the identity actions (member.invited, member.joined, member.roles_changed, …).
 *
 * @property int $id
 * @property int $organization_id
 * @property int $user_id
 * @property MembershipStatus $status
 * @property int|null $default_group_id
 * @property-read Organization $organization non-null FK (cascade on delete)
 * @property-read User $user non-null FK (cascade on delete)
 */
#[Fillable(['organization_id', 'user_id', 'status', 'default_group_id', 'invited_at', 'joined_at'])]
#[Table(name: 'organization_user')]
class Membership extends Pivot
{
    public $incrementing = true;

    protected function casts(): array
    {
        return [
            'status' => MembershipStatus::class,
            'invited_at' => 'datetime',
            'joined_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Group, $this> */
    public function defaultGroup(): BelongsTo
    {
        return $this->belongsTo(Group::class, 'default_group_id');
    }
}
