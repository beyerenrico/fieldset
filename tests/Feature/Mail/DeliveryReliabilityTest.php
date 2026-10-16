<?php

declare(strict_types=1);

use App\Domain\Audit\AuditLogger;
use App\Domain\Audit\Models\Activity;
use App\Domain\Mail\Actions\QueueMail;
use App\Domain\Mail\Actions\RetryMail;
use App\Domain\Mail\Delivery\DocumentDelivery;
use App\Domain\Mail\Enums\MailStatus;
use App\Domain\Mail\Enums\SmtpEncryption;
use App\Domain\Mail\Jobs\DeliverMail;
use App\Domain\Mail\Mailables\BrandedMail;
use App\Domain\Mail\Mailables\TestMail;
use App\Domain\Mail\Models\DocumentLink;
use App\Domain\Mail\Models\MailMessage;
use App\Domain\Mail\Models\MailSetting;
use App\Domain\Mail\Sending\OrganizationMailer;
use App\Domain\Mail\Templates\MailTemplate;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\Mailer\Exception\TransportException;
use Tests\Feature\Mail\MailFixtures;

/* M3 review #3/#4: no double sends, deterministic Message-ID, fresh links on every delivery attempt. */

beforeEach(function (): void {
    MailFixtures::fakeDisk();
    $this->org = MailFixtures::organization();
    $this->queue = app(QueueMail::class);
});

function reliabilityDeliver(int $id): void
{
    new DeliverMail($id)->handle(app(OrganizationMailer::class), app(AuditLogger::class));
}

it('does not resend a message an interrupted attempt left in sending, but marks it failed for a manual retry', function (): void {
    Queue::fake();
    $message = $this->queue->handle(new TestMail($this->org->id), 'ann@example.com');
    MailMessage::query()->whereKey($message->id)->update(['status' => MailStatus::Sending->value]);

    reliabilityDeliver($message->id);

    expect(MailFixtures::arrayTransport()->messages())->toHaveCount(0)
        ->and($message->fresh()->status)->toBe(MailStatus::Failed)
        ->and($message->fresh()->error)->toContain('interrupted')
        ->and($message->fresh()->isRetryable())->toBeTrue();
});

it('puts the message back to queued with the error when the transport throws, so the next attempt can claim it', function (): void {
    config(['messaging.smtp.allow_private_hosts' => true]);
    Queue::fake();
    MailSetting::factory()->withSmtp('127.0.0.1', 9, SmtpEncryption::None)->create();
    $message = $this->queue->handle(new TestMail($this->org->id), 'ann@example.com');

    expect(fn () => reliabilityDeliver($message->id))->toThrow(TransportException::class);

    expect($message->fresh())->status->toBe(MailStatus::Queued)->attempts->toBe(1)->error->toContain('127.0.0.1');
});

it('lets only one of two concurrent retries dispatch the delivery', function (): void {
    Queue::fake();
    $message = $this->queue->handle(new TestMail($this->org->id), 'ann@example.com');
    $message->markFailed('Connection refused');
    $first = MailMessage::query()->findOrFail($message->id);
    $second = MailMessage::query()->findOrFail($message->id); // stale copy, still "failed"

    app(RetryMail::class)->handle($first);

    expect(fn () => app(RetryMail::class)->handle($second))->toThrow(LogicException::class);
    Queue::assertPushed(DeliverMail::class, 2); // the original delivery and one retry
    expect(Activity::query()->where('event', 'mail.retried')->count())->toBe(1);
});

it('sends with a deterministic Message-ID and records it', function (): void {
    $message = $this->queue->handle(new TestMail($this->org->id), 'ann@example.com');
    $host = parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'localhost';

    expect(MailFixtures::lastSentEmail()->getHeaders()->get('Message-ID')?->getBodyAsString())->toBe("<mail-{$message->id}@{$host}>")
        ->and($message->fresh()->message_id)->toBe("mail-{$message->id}@{$host}");
});

it('keeps the job timeout below the queue retry_after', function (): void {
    $timeout = new ReflectionClass(DeliverMail::class)->getAttributes(Timeout::class)[0]->newInstance()->timeout;

    expect($timeout)->toBeLessThan((int) config('queue.connections.redis.retry_after'))
        ->and($timeout)->toBeLessThan((int) config('queue.connections.database.retry_after'));
});

it('leaves a sent message unrecorded (sending) when recording fails, so a retry never resends it', function (): void {
    config(['messaging.attachment_max_bytes' => 10_000]);
    Queue::fake();
    $plan = app(DocumentDelivery::class)->plan(MailFixtures::document(100));
    $message = $this->queue->handle(new BrandedMail($this->org->id, new MailTemplate('Doc', 'Body'), [], $plan), 'ann@example.com');
    Activity::creating(static fn (): never => throw new RuntimeException('audit store down'));

    expect(fn () => reliabilityDeliver($message->id))->toThrow(RuntimeException::class, 'audit store down');
    expect($message->fresh()->status)->toBe(MailStatus::Sending)->and(MailFixtures::arrayTransport()->messages())->toHaveCount(1);

    Activity::flushEventListeners();
    reliabilityDeliver($message->id);
    expect($message->fresh()->status)->toBe(MailStatus::Failed)->and(MailFixtures::arrayTransport()->messages())->toHaveCount(1);
});

it('refreshes document links on every delivery attempt, so a retried mail never carries an expired link', function (): void {
    config(['messaging.attachment_max_bytes' => 10, 'messaging.links.ttl_days' => 14]);
    Queue::fake();
    $plan = app(DocumentDelivery::class)->plan(MailFixtures::document(5000));
    $template = new MailTemplate('Doc', 'Valid until {{document.expires}}: {{document.link}}');
    $message = $this->queue->handle(new BrandedMail($this->org->id, $template, [], $plan), 'ann@example.com');
    $this->travel(20)->days();

    reliabilityDeliver($message->id);

    $link = DocumentLink::query()->sole();
    $expires = now()->addDays(14);
    expect($link->expires_at->isSameDay($expires))->toBeTrue()
        ->and((string) MailFixtures::lastSentEmail()->getHtmlBody())->toContain($expires->settings(['locale' => 'en'])->isoFormat('L'))
        ->and(Activity::query()->where('event', 'document.sent')->sole()->properties['linked'][0]['expires_at'])->toBe($link->expires_at->toIso8601String());
});
