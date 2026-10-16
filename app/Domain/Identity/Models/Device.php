<?php

declare(strict_types=1);

namespace App\Domain\Identity\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A Field App install of a user (§6.4, §9.3). Only a hash of the device secret is stored. Devices are
 * user-level (not tenant-owned): one phone serves all organizations of its user.
 *
 * @property int $id
 * @property int $user_id
 * @property string $device_uuid
 * @property string $secret_hash
 * @property string|null $name
 * @property string|null $platform
 * @property Carbon|null $last_seen_at
 * @property Carbon|null $revoked_at
 * @property Carbon|null $wipe_requested_at
 * @property-read User $user
 */
class Device extends Model
{
    protected $fillable = ['user_id', 'device_uuid', 'secret_hash', 'name', 'platform'];

    protected $hidden = ['secret_hash'];

    protected $attributes = ['name' => null, 'platform' => null, 'last_seen_at' => null, 'revoked_at' => null, 'wipe_requested_at' => null];

    protected function casts(): array
    {
        return ['last_seen_at' => 'datetime', 'revoked_at' => 'datetime', 'wipe_requested_at' => 'datetime'];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function mustWipe(): bool
    {
        return $this->revoked_at !== null || $this->wipe_requested_at !== null || ! $this->user->isActive();
    }
}
