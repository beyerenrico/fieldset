<?php

declare(strict_types=1);

namespace App\Domain\Mail\Models;

use __PHP_Incomplete_Class;
use App\Domain\Audit\ErrorText;
use App\Domain\Identity\Tenancy\BelongsToOrganization;
use App\Domain\Mail\Delivery\DeliveredLink;
use App\Domain\Mail\Delivery\DeliveryPlan;
use App\Domain\Mail\Delivery\DocumentFile;
use App\Domain\Mail\Enums\MailStatus;
use App\Domain\Mail\Mailables\BrandedMail;
use App\Domain\Mail\Mailables\TestMail;
use App\Domain\Mail\Policies\MailMessagePolicy;
use App\Domain\Mail\Templates\MailTemplate;
use App\Domain\Notifications\Mail\NotificationMail;
use App\Domain\Reports\Scheduling\ScheduledReportMail;
use Carbon\CarbonImmutable;
use Database\Factories\MailMessageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use SplObjectStorage;
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
 * @property int|null $workflow_step_run_id set for mails of a workflow `send_email` step (caps, ADR 0032)
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

    /** Mailables the log may hold. */
    public const array MAILABLE_CLASSES = [
        BrandedMail::class,
        TestMail::class,
        NotificationMail::class,
        ScheduledReportMail::class,
    ];

    /** Every class `unserialize` may instantiate for a payload (KTD10); a test derives it from real payloads. */
    public const array PAYLOAD_CLASSES = [
        ...self::MAILABLE_CLASSES,
        MailTemplate::class,
        DeliveryPlan::class,
        DocumentFile::class,
        DeliveredLink::class,
        CarbonImmutable::class,
    ];

    /** @use HasFactory<MailMessageFactory> */
    use HasFactory;

    protected $attributes = [
        'recipient_name' => null, 'status' => 'queued', 'submission_uuid' => null, 'workflow_step_run_id' => null, 'message_id' => null,
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
        $mailable = in_array($this->mailable_class, self::MAILABLE_CLASSES, true)
            ? unserialize($this->payload, ['allowed_classes' => self::PAYLOAD_CLASSES])
            : false;
        $refused = self::incompleteClass($mailable);

        if ($refused !== null) {
            Log::warning('Mail payload refused: class not allowed', ['mail_message_id' => $this->id, 'class' => $refused]);
        }
        if ($refused !== null || ! $mailable instanceof Mailable) {
            throw new UnexpectedValueException("Mail message {$this->id} does not hold a mailable.");
        }

        return $mailable;
    }

    /**
     * Name of the first class `unserialize` refused to instantiate anywhere in the graph.
     *
     * @param  SplObjectStorage<object, null>|null  $seen
     */
    private static function incompleteClass(mixed $value, ?SplObjectStorage $seen = null): ?string
    {
        $seen ??= new SplObjectStorage;
        if (is_object($value)) {
            if ($seen->offsetExists($value)) {
                return null;
            }
            $seen->offsetSet($value);
            if ($value instanceof __PHP_Incomplete_Class) {
                $name = ((array) $value)['__PHP_Incomplete_Class_Name'] ?? null;

                return is_string($name) ? $name : '(unknown)';
            }
            $value = (array) $value;
        }
        if (is_array($value)) {
            foreach ($value as $item) {
                $found = self::incompleteClass($item, $seen);
                if ($found !== null) {
                    return $found;
                }
            }
        }

        return null;
    }

    public function isRetryable(): bool
    {
        return $this->status === MailStatus::Failed;
    }

    /**
     * Atomically moves the message from `$from` to `$to` (one conditional UPDATE) and refreshes the model.
     * Only the caller that changed the row may act on it: two workers or two retries never both win.
     *
     * @param  array<string, mixed>  $extra
     */
    public function transition(MailStatus $from, MailStatus $to, array $extra = []): bool
    {
        // By primary key like save(): the row is already loaded, the worker's tenant may not be set yet.
        $changed = $this->newModelQuery()->whereKey($this->id)->where('status', $from->value)
            ->update([...$extra, 'status' => $to->value]) === 1;
        if ($changed) {
            $this->refresh();
        }

        return $changed;
    }

    /** Claims a queued message for one delivery attempt (queued → sending). */
    public function claimForSending(): bool
    {
        return $this->transition(MailStatus::Queued, MailStatus::Sending, ['attempts' => DB::raw('attempts + 1')]);
    }

    /** The transport threw: the message was not accepted, so the next attempt may claim it again. */
    public function releaseAfterError(string $error): void
    {
        $this->transition(MailStatus::Sending, MailStatus::Queued, ['error' => ErrorText::scrub($error)]);
    }

    /** Message-ID set on every attempt, so receivers can drop a duplicate of the same log entry. */
    public function messageIdHeader(): string
    {
        $host = parse_url((string) config('app.url'), PHP_URL_HOST);

        return 'mail-'.$this->id.'@'.(is_string($host) && $host !== '' ? $host : 'localhost');
    }

    public function markSent(?string $messageId): void
    {
        $this->forceFill([
            'status' => MailStatus::Sent, 'message_id' => $messageId, 'error' => null, 'sent_at' => now(), 'failed_at' => null,
        ])->save();
    }

    public function markFailed(string $error): void
    {
        $this->forceFill(['status' => MailStatus::Failed, 'error' => ErrorText::scrub($error), 'failed_at' => now()])->save();
    }
}
