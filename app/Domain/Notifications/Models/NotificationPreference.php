<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Models;

use App\Domain\Identity\Models\User;
use App\Policies\InternalRecordPolicy;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One explicit choice of a user: event × channel on or off (§8.9). User-level, not tenant-owned.
 * Read and written only through ChannelPreferences for the authenticated user, never exposed as a
 * model (hence the deny-all policy).
 *
 * @property int $id
 * @property int $user_id
 * @property string $event
 * @property string $channel
 * @property bool $enabled
 */
#[Fillable(['user_id', 'event', 'channel', 'enabled'])]
#[UsePolicy(InternalRecordPolicy::class)]
class NotificationPreference extends Model
{
    protected function casts(): array
    {
        return ['enabled' => 'boolean'];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
