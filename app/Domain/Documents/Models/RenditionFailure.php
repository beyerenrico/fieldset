<?php

declare(strict_types=1);

namespace App\Domain\Documents\Models;

use App\Domain\Identity\Tenancy\BelongsToOrganization;
use App\Domain\Submissions\Models\Submission;
use App\Policies\InternalRecordPolicy;
use Database\Factories\RenditionFailureFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * The permanent render failure of a submission (ADR 0031): one row per submission, written when the
 * render job gave up, removed by the next successful render or a new submit. The API answers
 * `document_failed`, the inbox shows a fixed text per reason, the sweep leaves the submission alone.
 *
 * @property int $id
 * @property int $organization_id
 * @property string $submission_uuid
 * @property string $reason
 * @property string|null $message
 * @property int $attempts
 * @property Carbon $failed_at
 * @property-read Submission $submission
 */
#[UseFactory(RenditionFailureFactory::class)]
#[UsePolicy(InternalRecordPolicy::class)]
#[Fillable(['submission_uuid', 'reason', 'message', 'attempts', 'failed_at'])]
class RenditionFailure extends Model
{
    use BelongsToOrganization;

    /** @use HasFactory<RenditionFailureFactory> */
    use HasFactory;

    /** The inlined document exceeds documents.max_request_bytes; retrying cannot help. */
    public const string BUDGET_EXCEEDED = 'budget_exceeded';

    /** Gotenberg failed or was unreachable on every attempt. */
    public const string RENDER_FAILED = 'render_failed';

    protected $attributes = ['message' => null, 'attempts' => 1];

    protected function casts(): array
    {
        return ['attempts' => 'integer', 'failed_at' => 'datetime'];
    }

    /** Fixed, translated text for users (the stored message is technical and never shown). */
    public function userMessage(): string
    {
        return (string) __('documents.errors.failed.'.$this->reason);
    }

    /** @return BelongsTo<Submission, $this> */
    public function submission(): BelongsTo
    {
        return $this->belongsTo(Submission::class, 'submission_uuid', 'uuid');
    }
}
