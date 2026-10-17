<?php

declare(strict_types=1);

namespace App\Domain\Platform\Models;

use App\Domain\Identity\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A platform operator grant (ADR 0041). Not tenant-owned: operators act across organizations.
 * Rows are written only by GrantPlatformAdmin / RevokePlatformAdmin (console), never by a UI.
 *
 * @property int $id
 * @property int $user_id
 * @property int|null $granted_by
 * @property-read User $user non-null FK (cascade on delete)
 */
#[Fillable(['user_id', 'granted_by'])]
final class PlatformAdmin extends Model
{
    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
