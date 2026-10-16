<?php

declare(strict_types=1);

namespace App\Domain\Submissions\Models;

use App\Domain\Forms\Casts\JsonTree;
use App\Domain\Identity\Tenancy\BelongsToOrganization;
use Database\Factories\IdempotencyKeyFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Stored response of an idempotent request (`Idempotency-Key`, §9.2): replays get the same answer.
 *
 * @property int $id
 * @property int $organization_id
 * @property int $user_id
 * @property string $key
 * @property string $request_hash
 * @property int $status_code
 * @property mixed $response
 */
#[UseFactory(IdempotencyKeyFactory::class)]
class IdempotencyKey extends Model
{
    use BelongsToOrganization;

    /** @use HasFactory<IdempotencyKeyFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    protected $fillable = ['user_id', 'key', 'request_hash', 'status_code', 'response'];

    protected function casts(): array
    {
        return ['response' => JsonTree::class, 'status_code' => 'integer'];
    }
}
