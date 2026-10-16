<?php

declare(strict_types=1);

use App\Domain\Mail\Actions\QueueMail;
use App\Domain\Mail\Enums\MailStatus;
use App\Domain\Mail\Enums\SmtpEncryption;
use App\Domain\Mail\Mailables\TestMail;
use App\Domain\Mail\Models\MailMessage;
use App\Domain\Mail\Models\MailSetting;
use App\Domain\Mail\Sending\OrganizationMailer;
use App\Domain\Mail\Sending\SmtpHostGuard;
use Illuminate\Support\Facades\Mail;
use Tests\Feature\Mail\MailFixtures;

beforeEach(function (): void {
    config(['mail.from.address' => 'noreply@forms.example']);
    $this->org = MailFixtures::organization('Acme GmbH');
    Mail::fake();
});

function sendTestMail(): MailMessage
{
    return app(QueueMail::class)->handle(new TestMail(test()->org->id), 'customer@example.com', 'Customer');
}

it('sends from the instance address with the organization name when nothing is configured', function (): void {
    sendTestMail();

    Mail::assertSent(TestMail::class, fn (TestMail $mail): bool => $mail->hasFrom('noreply@forms.example', 'Acme GmbH')
        && $mail->replyTo === [] && $mail->mailer === null);
});

it('applies the sender name and reply-to but keeps the instance From address', function (): void {
    MailSetting::factory()->create(['sender_name' => 'Acme Service', 'reply_to_address' => 'office@acme.example']);

    sendTestMail();

    Mail::assertSent(TestMail::class, fn (TestMail $mail): bool => $mail->hasFrom('noreply@forms.example', 'Acme Service')
        && $mail->hasReplyTo('office@acme.example') && $mail->mailer === null);
});

it('uses the organization SMTP transport and From address when configured', function (): void {
    config(['messaging.smtp.allow_private_hosts' => true]);
    $settings = MailSetting::factory()->withSmtp('10.0.0.25', 2525, SmtpEncryption::StartTls)->create(['sender_name' => 'Acme Service']);
    $mailer = OrganizationMailer::MAILER_PREFIX.$this->org->id;

    sendTestMail();

    Mail::assertSent(TestMail::class, fn (TestMail $mail): bool => $mail->hasFrom('office@example.com', 'Acme Service') && $mail->mailer === $mailer);
    expect(config("mail.mailers.{$mailer}"))->toMatchArray([
        'transport' => 'smtp', 'scheme' => 'smtp', 'host' => '10.0.0.25', 'port' => 2525,
        'username' => 'mailer', 'password' => 'smtp-secret', 'auto_tls' => true, 'require_tls' => true,
    ])->and($settings->organization_id)->toBe($this->org->id);
});

it('falls back to the instance mailer when the own SMTP is disabled or incomplete', function (array $state): void {
    MailSetting::factory()->withSmtp()->create($state);

    sendTestMail();

    Mail::assertSent(TestMail::class, fn (TestMail $mail): bool => $mail->hasFrom('noreply@forms.example') && $mail->mailer === null);
})->with([
    'disabled' => [['smtp_enabled' => false]],
    'no from address' => [['smtp_from_address' => null]],
]);

it('maps the encryption choice to the SMTP transport options', function (SmtpEncryption $encryption, string $scheme, bool $autoTls, bool $requireTls): void {
    $settings = MailSetting::factory()->withSmtp('smtp.example.com', 0, $encryption)->make(['smtp_port' => null, 'smtp_username' => null]);

    expect(app(OrganizationMailer::class)->smtpConfig($settings))->toMatchArray([
        'scheme' => $scheme, 'port' => $encryption->defaultPort(), 'auto_tls' => $autoTls, 'require_tls' => $requireTls,
        'username' => null, 'password' => null, // no password without a user name
    ]);
})->with([
    'starttls' => [SmtpEncryption::StartTls, 'smtp', true, true],
    'tls' => [SmtpEncryption::Tls, 'smtps', true, false],
    'none' => [SmtpEncryption::None, 'smtp', false, false],
]);

it('refuses private SMTP hosts at send time (SSRF guard) and logs the failure', function (): void {
    MailSetting::factory()->withSmtp('169.254.169.254', 25, SmtpEncryption::None)->create();

    expect(fn () => sendTestMail())->toThrow(RuntimeException::class, 'is not allowed');
    expect(MailMessage::query()->sole())->status->toBe(MailStatus::Failed)->error->toContain('not allowed');
});

it('allows only globally routable SMTP hosts unless private hosts are enabled', function (string $host, bool $allowed): void {
    expect(app(SmtpHostGuard::class)->isAllowed($host))->toBe($allowed);

    config(['messaging.smtp.allow_private_hosts' => true]);
    expect(app(SmtpHostGuard::class)->isAllowed($host))->toBeTrue();
})->with([
    'public v4' => ['8.8.8.8', true],
    'loopback' => ['127.0.0.1', false],
    'private' => ['10.1.2.3', false],
    'link-local metadata' => ['169.254.169.254', false],
    'carrier-grade nat' => ['100.64.0.1', false],
    'ipv6 loopback' => ['[::1]', false],
    'unresolvable' => ['does-not-exist.invalid', false],
]);
