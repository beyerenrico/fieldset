<?php

declare(strict_types=1);

use App\Domain\Audit\AuditLogger;
use App\Domain\Audit\Models\Activity;
use App\Domain\Identity\Tenancy\TenantContext;
use App\Domain\Mail\Actions\QueueMail;
use App\Domain\Mail\Actions\RetryMail;
use App\Domain\Mail\Enums\MailStatus;
use App\Domain\Mail\Enums\SmtpEncryption;
use App\Domain\Mail\Jobs\DeliverMail;
use App\Domain\Mail\Mailables\TestMail;
use App\Domain\Mail\Models\MailMessage;
use App\Domain\Mail\Models\MailSetting;
use App\Domain\Mail\Sending\OrganizationMailer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\Mailer\Exception\TransportException;
use Tests\Feature\Mail\MailFixtures;

beforeEach(function (): void {
    $this->org = MailFixtures::organization();
    $this->queue = app(QueueMail::class);
});

it('logs every mail as queued and dispatches the delivery job', function (): void {
    Queue::fake();

    $message = $this->queue->handle(new TestMail($this->org->id), 'ann@example.com', 'Ann Customer');

    expect($message->fresh())
        ->status->toBe(MailStatus::Queued)
        ->recipient_email->toBe('ann@example.com')
        ->recipient_name->toBe('Ann Customer')
        ->subject->toBe('Test email from Acme GmbH')
        ->mailable_class->toBe(TestMail::class)
        ->attempts->toBe(0)
        ->and(new TestMail($this->org->id))->not->toBeInstanceOf(ShouldQueue::class)
        ->and(DB::table('mail_messages')->value('payload'))->not->toContain('TestMail'); // encrypted at rest
    Queue::assertPushed(DeliverMail::class, fn (DeliverMail $job): bool => $job->mailMessageId === $message->id && $job->afterCommit === true);
});

it('sends queued mail and records the transport message id', function (): void {
    $message = $this->queue->handle(new TestMail($this->org->id), 'ann@example.com', 'Ann Customer');

    $email = MailFixtures::lastSentEmail();
    expect($message->fresh())
        ->status->toBe(MailStatus::Sent)
        ->attempts->toBe(1)
        ->sent_at->not->toBeNull()
        ->message_id->not->toBeEmpty()
        ->and($email->getTo()[0]->getAddress())->toBe('ann@example.com')
        ->and($email->getSubject())->toBe('Test email from Acme GmbH')
        ->and($email->getHtmlBody())->toContain('Hello Ann Customer');
});

it('renders the logged subject and the mail in the organization locale', function (): void {
    $this->org->update(['locale' => 'de']);
    Mail::fake();

    $message = $this->queue->handle(new TestMail($this->org->id), 'ann@example.com', 'Ann');

    expect($message->subject)->toBe('Test-E-Mail von Acme GmbH');
    Mail::assertSent(TestMail::class, fn (TestMail $mail): bool => $mail->locale === 'de');
});

it('rejects invalid recipients and mailables of another organization', function (): void {
    $other = makeOrganization(name: 'Other');

    expect(fn () => $this->queue->handle(new TestMail($this->org->id), 'not-an-email'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $this->queue->handle(new TestMail($other->id), 'ann@example.com'))->toThrow(LogicException::class)
        ->and(MailMessage::query()->count())->toBe(0);
});

it('marks failed mail with an error free of credentials and retries it', function (): void {
    config(['messaging.smtp.allow_private_hosts' => true]);
    $settings = MailSetting::factory()->withSmtp('127.0.0.1', 9, SmtpEncryption::None)->create([
        'smtp_username' => 'mailer-user', 'smtp_password' => 'pa55-secret',
    ]);

    expect(fn () => $this->queue->handle(new TestMail($this->org->id), 'ann@example.com'))->toThrow(TransportException::class);

    $message = MailMessage::query()->sole();
    expect($message)
        ->status->toBe(MailStatus::Failed)
        ->attempts->toBe(1)
        ->failed_at->not->toBeNull()
        ->error->toContain('127.0.0.1')
        ->error->not->toContain('pa55-secret')
        ->error->not->toContain('mailer-user');

    $settings->update(['smtp_enabled' => false]);
    app(RetryMail::class)->handle($message, ownerOf($this->org));

    expect($message->fresh())
        ->status->toBe(MailStatus::Sent)
        ->attempts->toBe(2)
        ->error->toBeNull()
        ->and(Activity::query()->where('event', 'mail.retried')->count())->toBe(1)
        ->and(MailFixtures::lastSentEmail()->getTo()[0]->getAddress())->toBe('ann@example.com');
});

it('retries only failed mail', function (): void {
    Queue::fake();
    $message = $this->queue->handle(new TestMail($this->org->id), 'ann@example.com');

    expect(fn () => app(RetryMail::class)->handle($message))->toThrow(LogicException::class);
});

it('does not send a message twice when the job runs again after success', function (): void {
    $message = $this->queue->handle(new TestMail($this->org->id), 'ann@example.com');
    MailFixtures::arrayTransport()->flush();

    new DeliverMail($message->id)->handle(app(OrganizationMailer::class), app(AuditLogger::class));

    expect(MailFixtures::arrayTransport()->messages())->toHaveCount(0)
        ->and($message->fresh()->attempts)->toBe(1);
});

it('delivers from a real queue worker with the tenant restored from the job context', function (): void {
    config(['queue.default' => 'database']);
    $message = $this->queue->handle(new TestMail($this->org->id), 'ann@example.com', 'Ann');
    expect($message->fresh()->status)->toBe(MailStatus::Queued);

    // Fresh worker state: no tenant, no Context (see QueuedJobTenancyTest).
    app(TenantContext::class)->clear();
    Context::flush();
    app()->forgetScopedInstances();
    Artisan::call('queue:work', ['connection' => 'database', '--max-jobs' => 1, '--stop-when-empty' => true, '--tries' => 1, '--memory' => 4096]);

    app(TenantContext::class)->set($this->org);
    expect($message->fresh())->status->toBe(MailStatus::Sent)->attempts->toBe(1)
        ->and(DB::table('failed_jobs')->count())->toBe(0)
        ->and(MailFixtures::lastSentEmail()->getSubject())->toBe('Test email from Acme GmbH');
});
