<?php

declare(strict_types=1);

namespace App\Domain\Submissions\Models;

use App\Domain\Identity\Tenancy\BelongsToOrganization;
use Database\Factories\IdempotencyKeyFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;

/**
 * Stored successful response of an idempotent request (`Idempotency-Key`, §9.2): replays get the same
 * body byte for byte. Unique per organization, user and key; pruned after `submissions.idempotency_ttl_hours`.
 *
 * @property int $id
 * @property int $organization_id
 * @property int $user_id
 * @property string $key
 * @property string $request_hash
 * @property int $status_code
 * @property string $response raw JSON body
 */
#[UseFactory(IdempotencyKeyFactory::class)]
#[Fillable(['user_id', 'key', 'request_hash', 'status_code', 'response'])]
class IdempotencyKey extends Model
{
    use BelongsToOrganization;

    /** @use HasFactory<IdempotencyKeyFactory> */
    use HasFactory;

    use MassPrunable;

    public const UPDATED_AT = null;

    /**
     * Platform-level housekeeping across all organizations (`model:prune` runs without a tenant).
     *
     * @return Builder<static>
     */
    public function prunable(): Builder
    {
        $hours = max(1, (int) config('submissions.idempotency_ttl_hours', 24));

        return static::query()->withoutTenant()->where('created_at', '<', now()->subHours($hours));
    }

    protected function casts(): array
    {
        return ['status_code' => 'integer'];
    }
}
