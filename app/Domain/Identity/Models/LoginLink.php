<?php

declare(strict_types=1);

namespace App\Domain\Identity\Models;

use App\Domain\Identity\LoginLinks\LoginLinkKind;
use App\Policies\InternalRecordPolicy;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A one-time sign-in link (ADR 0056, ADR 0068). Not tenant-owned: users are global. Only the SHA-256 hash of
 * the token is stored; the token itself travels in the URL fragment and is shown once. Rows (with the IP that
 * used them) are pruned PRUNE_AFTER_DAYS after expiry by the scheduled `model:prune`.
 *
 * @property int $id
 * @property int $user_id
 * @property LoginLinkKind $kind
 * @property string $token_hash
 * @property Carbon $expires_at
 * @property Carbon|null $used_at
 * @property string|null $used_ip
 * @property string $created_by actor of the issuer ("cli")
 * @property int|null $created_by_uid host UID of the operator who ran the command
 * @property Carbon|null $created_at
 * @property-read User $user non-null FK (cascade on delete)
 */
#[Fillable(['user_id', 'kind', 'token_hash', 'expires_at', 'created_by', 'created_by_uid'])]
#[Hidden(['token_hash'])]
#[UsePolicy(InternalRecordPolicy::class)] // console only (fsa): never exposed through a UI or API
final class LoginLink extends Model
{
    use MassPrunable;

    /** Days after expiry until a link and its used IP are deleted (privacy: nothing is kept forever). */
    public const int PRUNE_AFTER_DAYS = 30;

    protected function casts(): array
    {
        return [
            'kind' => LoginLinkKind::class,
            'expires_at' => 'datetime',
            'used_at' => 'datetime',
            'created_by_uid' => 'integer',
        ];
    }

    /** @return Builder<static> */
    public function prunable(): Builder
    {
        return self::query()->where('expires_at', '<', now()->subDays(self::PRUNE_AFTER_DAYS));
    }

    public static function hashToken(#[\SensitiveParameter] string $token): string
    {
        return hash('sha256', $token);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isUsed(): bool
    {
        return $this->used_at !== null;
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }
}
