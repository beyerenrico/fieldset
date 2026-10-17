<?php

declare(strict_types=1);

namespace App\Domain\Mail\Models;

use App\Domain\Audit\ErrorText;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Tenancy\BelongsToOrganization;
use App\Domain\Mail\Enums\DocumentSendFailure;
use App\Domain\Mail\Enums\DocumentSendStatus;
use App\Domain\Mail\Policies\DocumentSendPolicy;
use App\Domain\Submissions\Models\Submission;
use Database\Factories\DocumentSendFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One "send to customer" request (§9.2, M3-T08): waits for the first rendition, then is queued as a
 * branded mail with the PDF (attachment or expiring link) and points at its mail log entry.
 *
 * @property int $id
 * @property int $organization_id
 * @property string $submission_uuid
 * @property string $recipient_email
 * @property string|null $recipient_name
 * @property DocumentSendStatus $status
 * @property int|null $requested_by
 * @property int|null $mail_message_id
 * @property int|null $rendition_id
 * @property string|null $error
 * @property string|null $idempotency_key the `Idempotency-Key` of the API request that created it (unique per submission)
 * @property DocumentSendFailure|null $failure_reason
 * @property Carbon|null $queued_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Submission $submission
 * @property-read User|null $requester
 * @property-read MailMessage|null $mailMessage
 */
#[UseFactory(DocumentSendFactory::class)]
#[UsePolicy(DocumentSendPolicy::class)]
#[Fillable(['submission_uuid', 'recipient_email', 'recipient_name', 'status', 'requested_by', 'idempotency_key'])]
class DocumentSend extends Model
{
    use BelongsToOrganization;

    /** @use HasFactory<DocumentSendFactory> */
    use HasFactory;

    protected $attributes = [
        'recipient_name' => null, 'status' => 'waiting_for_document', 'requested_by' => null, 'mail_message_id' => null,
        'rendition_id' => null, 'error' => null, 'failure_reason' => null, 'queued_at' => null, 'idempotency_key' => null,
    ];

    protected function casts(): array
    {
        return ['status' => DocumentSendStatus::class, 'failure_reason' => DocumentSendFailure::class, 'queued_at' => 'datetime'];
    }

    /**
     * Fails the waiting requests of a submission in one conditional update, so a request that was queued
     * meanwhile is never overwritten. Returns the number of failed requests.
     */
    public static function failWaiting(string $submissionUuid, DocumentSendFailure $reason, ?string $error = null): int
    {
        return static::query()->where('submission_uuid', $submissionUuid)
            ->where('status', DocumentSendStatus::WaitingForDocument->value)
            ->update([
                'status' => DocumentSendStatus::Failed->value, 'failure_reason' => $reason->value,
                'error' => $error === null ? null : ErrorText::scrub($error), 'updated_at' => now(),
            ]);
    }

    public function markQueued(int $mailMessageId, int $renditionId): void
    {
        $this->forceFill([
            'status' => DocumentSendStatus::Queued, 'mail_message_id' => $mailMessageId, 'rendition_id' => $renditionId,
            'queued_at' => now(), 'error' => null,
        ])->save();
    }

    public function markFailed(DocumentSendFailure $reason, ?string $error = null): void
    {
        $this->forceFill([
            'status' => DocumentSendStatus::Failed, 'failure_reason' => $reason, 'error' => $error === null ? null : ErrorText::scrub($error),
        ])->save();
    }

    /** @return BelongsTo<Submission, $this> */
    public function submission(): BelongsTo
    {
        return $this->belongsTo(Submission::class, 'submission_uuid', 'uuid');
    }

    /** @return BelongsTo<User, $this> */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /** @return BelongsTo<MailMessage, $this> */
    public function mailMessage(): BelongsTo
    {
        return $this->belongsTo(MailMessage::class);
    }
}
