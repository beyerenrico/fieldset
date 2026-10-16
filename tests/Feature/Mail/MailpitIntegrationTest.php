<?php

declare(strict_types=1);

use App\Domain\Mail\Actions\QueueMail;
use App\Domain\Mail\Delivery\DocumentDelivery;
use App\Domain\Mail\Enums\MailStatus;
use App\Domain\Mail\Enums\SmtpEncryption;
use App\Domain\Mail\Mailables\BrandedMail;
use App\Domain\Mail\Models\MailMessage;
use App\Domain\Mail\Models\MailSetting;
use App\Domain\Mail\Templates\MailTemplate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Feature\Mail\MailFixtures;

/*
 * Real SMTP delivery into Mailpit (dev service, docker compose). Never skipped: Mailpit is part of
 * the development and CI services. Host defaults match .env.example (SMTP 11025, API 18025).
 */

function mailpitApi(string $path): string
{
    return rtrim((string) env('MAILPIT_API_URL', 'http://127.0.0.1:18025'), '/').'/api/v1/'.$path;
}

/** @return array<string, mixed> The full Mailpit message with the given subject. */
function mailpitMessage(string $subject): array
{
    for ($i = 0; $i < 30; $i++) {
        $found = Http::get(mailpitApi('search'), ['query' => 'subject:"'.$subject.'"'])->throw()->json('messages.0.ID');
        if (is_string($found)) {
            return Http::get(mailpitApi('message/'.$found))->throw()->json();
        }
        usleep(100_000);
    }

    throw new RuntimeException("Mail with subject [{$subject}] did not arrive in Mailpit.");
}

beforeEach(function (): void {
    Http::delete(mailpitApi('messages'))->throw();
    config([
        'mail.from.address' => 'noreply@forms.example',
        'mail.mailers.smtp.host' => env('MAILPIT_SMTP_HOST', '127.0.0.1'),
        'mail.mailers.smtp.port' => (int) env('MAILPIT_SMTP_PORT', 11025),
        'mail.mailers.smtp.scheme' => 'smtp',
    ]);
    MailFixtures::fakeDisk();
    $this->org = MailFixtures::organization('Acme GmbH');
    $this->subject = 'Delivery note '.Str::random(10);
});

function sendDocumentMail(string $subject): MailMessage
{
    $org = test()->org;
    $plan = app(DocumentDelivery::class)->plan(MailFixtures::document(2048, 'LS-2026-0042.pdf'));
    $template = new MailTemplate($subject, "Hello {{recipient.name}},\n\nplease find **{{document.name}}** attached.");

    return app(QueueMail::class)->handle(new BrandedMail($org->id, $template, [], $plan), 'customer@example.com', 'Carla Customer');
}

it('delivers a branded mail with the PDF attached over the instance SMTP transport', function (): void {
    config(['mail.default' => 'smtp']);
    $this->org->update(['branding' => ['primary_color' => '#0f766e', 'logo_path' => 'branding/logo.png']]);
    Storage::disk(MailFixtures::DISK)->put('branding/logo.png', base64_decode(MailFixtures::PNG));
    MailSetting::factory()->create(['sender_name' => 'Acme Service', 'reply_to_address' => 'office@acme.example']);

    $record = sendDocumentMail($this->subject);
    $mail = mailpitMessage($this->subject);

    expect($record->fresh())->status->toBe(MailStatus::Sent)->message_id->toBe($mail['ID']) // transport id ("queued as …"), what provider webhooks reference
        ->and($mail['From'])->toMatchArray(['Address' => 'noreply@forms.example', 'Name' => 'Acme Service'])
        ->and($mail['ReplyTo'][0]['Address'])->toBe('office@acme.example')
        ->and($mail['To'][0])->toMatchArray(['Address' => 'customer@example.com', 'Name' => 'Carla Customer'])
        ->and($mail['Attachments'])->toHaveCount(1)
        ->and($mail['Attachments'][0])->toMatchArray(['FileName' => 'LS-2026-0042.pdf', 'ContentType' => 'application/pdf'])
        ->and($mail['Inline'][0]['ContentID'])->toBe(BrandedMail::LOGO_CID)
        ->and($mail['HTML'])->toContain('#0f766e')->toContain('<strong>LS-2026-0042.pdf</strong>')
        ->and($mail['Text'])->toContain('Hello Carla Customer');
});

it('delivers through the organization\'s own SMTP server with its own From address', function (): void {
    config(['mail.default' => 'array', 'messaging.smtp.allow_private_hosts' => true]); // default mailer never reaches Mailpit
    MailSetting::factory()->withSmtp((string) env('MAILPIT_SMTP_HOST', '127.0.0.1'), (int) env('MAILPIT_SMTP_PORT', 11025), SmtpEncryption::None)
        ->create(['sender_name' => 'Acme Service', 'smtp_username' => null, 'smtp_password' => null, 'smtp_from_address' => 'office@acme.example']);

    $record = sendDocumentMail($this->subject);
    $mail = mailpitMessage($this->subject);

    expect($record->fresh()->status)->toBe(MailStatus::Sent)
        ->and($mail['From'])->toMatchArray(['Address' => 'office@acme.example', 'Name' => 'Acme Service'])
        ->and($mail['Attachments'][0]['FileName'])->toBe('LS-2026-0042.pdf')
        ->and(MailFixtures::arrayTransport()->messages())->toHaveCount(0);
});
