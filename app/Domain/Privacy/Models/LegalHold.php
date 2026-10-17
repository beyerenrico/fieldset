<?php

declare(strict_types=1);

namespace App\Domain\Privacy\Models;

use App\Domain\Forms\Models\Form;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Tenancy\BelongsToOrganization;
use App\Domain\Privacy\Policies\LegalHoldPolicy;
use App\Domain\Submissions\Models\Submission;
use Database\Factories\LegalHoldFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A legal hold on a form (all its submissions) or on one submission (ADR 0039): while active
 * (`released_at = null`) the retention job neither deletes nor anonymizes the held submissions. Released
 * holds stay as history. Placed and released only through PlaceLegalHold / ReleaseLegalHold (audited).
 *
 * @property int $id
 * @property int $organization_id
 * @property int|null $form_id
 * @property string|null $submission_uuid
 * @property string $reason
 * @property int|null $placed_by
 * @property int|null $released_by
 * @property Carbon|null $released_at
 * @property Carbon $created_at
 * @property-read Form|null $form
 * @property-read Submission|null $submission
 * @property-read User|null $placedBy
 */
#[UseFactory(LegalHoldFactory::class)]
#[UsePolicy(LegalHoldPolicy::class)]
#[Fillable(['form_id', 'submission_uuid', 'reason', 'placed_by'])]
class LegalHold extends Model
{
    use BelongsToOrganization;

    /** @use HasFactory<LegalHoldFactory> */
    use HasFactory;

    protected $attributes = ['form_id' => null, 'submission_uuid' => null, 'released_by' => null, 'released_at' => null];

    protected function casts(): array
    {
        return ['released_at' => 'datetime'];
    }

    public function isActive(): bool
    {
        return $this->released_at === null;
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('released_at');
    }

    /** @return BelongsTo<Form, $this> */
    public function form(): BelongsTo
    {
        return $this->belongsTo(Form::class);
    }

    /** @return BelongsTo<Submission, $this> */
    public function submission(): BelongsTo
    {
        return $this->belongsTo(Submission::class, 'submission_uuid', 'uuid');
    }

    /** @return BelongsTo<User, $this> */
    public function placedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'placed_by');
    }
}
