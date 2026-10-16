<?php

declare(strict_types=1);

namespace App\Domain\Submissions\Models;

use App\Domain\Identity\Models\User;
use App\Domain\Identity\Tenancy\BelongsToOrganization;
use Database\Factories\SubmissionEventFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Status history row (§6.4). Append-only: only the processing marker `workflows_processed_at` is set later.
 *
 * @property int $id
 * @property int $organization_id
 * @property string $submission_uuid
 * @property string|null $from_status
 * @property string $to_status
 * @property int|null $actor_id
 * @property string|null $comment
 * @property Carbon $created_at
 * @property Carbon|null $workflows_processed_at processing marker of the workflow trigger job (ADR 0032)
 */
#[UseFactory(SubmissionEventFactory::class)]
#[Fillable(['submission_uuid', 'from_status', 'to_status', 'actor_id', 'comment'])]
class SubmissionEvent extends Model
{
    use BelongsToOrganization;

    /** @use HasFactory<SubmissionEventFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    protected $attributes = ['from_status' => null, 'actor_id' => null, 'comment' => null];

    protected function casts(): array
    {
        return ['created_at' => 'datetime', 'workflows_processed_at' => 'datetime'];
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
