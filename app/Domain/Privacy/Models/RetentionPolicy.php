<?php

declare(strict_types=1);

namespace App\Domain\Privacy\Models;

use App\Domain\Forms\Models\Form;
use App\Domain\Identity\Tenancy\BelongsToOrganization;
use App\Domain\Privacy\Enums\RetentionAction;
use App\Domain\Privacy\Policies\RetentionPolicyPolicy;
use Database\Factories\RetentionPolicyFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Retention of one form's submissions (BUILD_PROMPT §11 Privacy, ADR 0039): after `months` the retention
 * job deletes or anonymizes them; `months = null` keeps them indefinitely. Changed only through
 * SaveRetentionPolicy (audited).
 *
 * @property int $id
 * @property int $organization_id
 * @property int $form_id
 * @property int|null $months
 * @property RetentionAction $action
 * @property int|null $updated_by
 * @property Carbon|null $last_applied_at
 * @property-read Form $form
 */
#[UseFactory(RetentionPolicyFactory::class)]
#[UsePolicy(RetentionPolicyPolicy::class)]
#[Fillable(['form_id', 'months', 'action', 'updated_by'])]
class RetentionPolicy extends Model
{
    use BelongsToOrganization;

    /** @use HasFactory<RetentionPolicyFactory> */
    use HasFactory;

    protected $attributes = ['months' => null, 'action' => 'anonymize', 'updated_by' => null, 'last_applied_at' => null];

    protected function casts(): array
    {
        return ['months' => 'integer', 'action' => RetentionAction::class, 'last_applied_at' => 'datetime'];
    }

    /** @return BelongsTo<Form, $this> */
    public function form(): BelongsTo
    {
        return $this->belongsTo(Form::class);
    }
}
