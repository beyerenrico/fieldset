<?php

declare(strict_types=1);

namespace App\Domain\Mail\Models;

use App\Domain\Identity\Tenancy\BelongsToOrganization;
use App\Domain\Mail\Enums\MailStatus;
use App\Domain\Mail\Policies\MailMessagePolicy;
use Database\Factories\MailMessageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Carbon;
use UnexpectedValueException;

/**
 * One outgoing mail in the delivery log (§8.8). The serialized mailable is kept (encrypted) so a
 * failed message can be retried with exactly the same content.
 *
 * @property int $id
 * @property int $organization_id
 * @property string $recipient_email
 * @property string|null $recipient_name
 * @property string $subject
 * @property MailStatus $status
 * @property string|null $submission_uuid
 * @property string $mailable_class
 * @property string|null $message_id
 * @property string|null $error
 * @property int $attempts
 * @property string $payload
 * @property Carbon $queued_at
 * @property Carbon|null $sent_at
 * @property Carbon|null $failed_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
#[UseFactory(MailMessageFactory::class)]
#[UsePolicy(MailMessagePolicy::class)]
#[Fillable(['recipient_email', 'recipient_name', 'subject', 'status', 'submission_uuid', 'mailable_class', 'payload', 'queued_at'])]
#[Hidden(['payload'])]
class MailMessage extends Model
{
    use BelongsToOrganization;

    /** @use HasFactory<MailMessageFactory> */
    use HasFactory;

    /** Longest error text kept per message. */
    public const int ERROR_LIMIT = 2000;

    protected $attributes = [
        'recipient_name' => null, 'status' => 'queued', 'submission_uuid' => null, 'message_id' => null,
        'error' => null, 'attempts' => 0, 'sent_at' => null, 'failed_at' => null,
    ];

    protected function casts(): array
    {
        return [
            'status' => MailStatus::class,
            'attempts' => 'integer',
            'payload' => 'encrypted',
            'queued_at' => 'datetime',
            'sent_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }

    /** The stored mailable, exactly as queued. */
    public function mailable(): Mailable
    {
        $mailable = unserialize($this->payload);

        if (! $mailable instanceof Mailable) {
            throw new UnexpectedValueException("Mail message {$this->id} does not hold a mailable.");
        }

        return $mailable;
    }

    public function isRetryable(): bool
    {
        return $this->status === MailStatus::Failed;
    }

    public function recordAttempt(): void
    {
        $this->increment('attempts');
    }

    public function recordError(string $error): void
    {
        $this->forceFill(['error' => mb_substr($error, 0, self::ERROR_LIMIT)])->save();
    }

    public function markSent(?string $messageId): void
    {
        $this->forceFill([
            'status' => MailStatus::Sent, 'message_id' => $messageId, 'error' => null, 'sent_at' => now(), 'failed_at' => null,
        ])->save();
    }

    public function markFailed(string $error): void
    {
        $this->forceFill([
            'status' => MailStatus::Failed, 'error' => mb_substr($error, 0, self::ERROR_LIMIT), 'failed_at' => now(),
        ])->save();
    }

    public function requeue(): void
    {
        $this->forceFill(['status' => MailStatus::Queued, 'error' => null, 'failed_at' => null, 'queued_at' => now()])->save();
    }
}
