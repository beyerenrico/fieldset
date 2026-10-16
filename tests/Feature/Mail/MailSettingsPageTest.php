<?php

declare(strict_types=1);

use App\Domain\Audit\Models\Activity;
use App\Domain\Identity\Enums\SystemRole;
use App\Domain\Mail\Enums\SmtpEncryption;
use App\Domain\Mail\Mailables\TestMail;
use App\Domain\Mail\Models\MailMessage;
use App\Domain\Mail\Models\MailSetting;
use App\Filament\Admin\Pages\MailSettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->org = makeOrganization();
    $this->owner = ownerOf($this->org);
    config(['messaging.smtp.allow_private_hosts' => true]);
});

it('is reachable for organization managers only', function (): void {
    actingInAdmin($this->owner, $this->org);
    $this->get(MailSettings::getUrl())->assertOk()->assertSee(__('mail.settings.send_test'));

    actingInAdmin(memberOf($this->org, SystemRole::FieldWorker), $this->org);
    $this->get(MailSettings::getUrl())->assertForbidden();
});

it('saves sender and SMTP settings with encrypted credentials and a redacted audit entry', function (): void {
    actingInAdmin($this->owner, $this->org);

    Livewire::test(MailSettings::class)
        ->fillForm([
            'sender_name' => 'Acme Service', 'reply_to_address' => 'office@acme.example', 'smtp_enabled' => true,
            'smtp_host' => 'smtp.acme.example', 'smtp_port' => 587, 'smtp_encryption' => 'starttls',
            'smtp_username' => 'smtp-user-77', 'smtp_password' => 'Sup3r-Secret!', 'smtp_from_address' => 'office@acme.example',
        ])
        ->call('save')
        ->assertHasNoFormErrors()
        ->assertFormSet(['smtp_password' => null, 'smtp_username' => 'smtp-user-77'])
        ->assertDontSee('Sup3r-Secret!');

    $settings = MailSetting::query()->sole();
    $raw = DB::table('mail_settings')->first();
    $audit = Activity::query()->where('event', 'mail_settings.created')->sole();
    expect($settings)
        ->sender_name->toBe('Acme Service')
        ->smtp_encryption->toBe(SmtpEncryption::StartTls)
        ->smtp_password->toBe('Sup3r-Secret!')
        ->and($raw->smtp_password)->not->toContain('Sup3r-Secret!')
        ->and($raw->smtp_username)->not->toContain('smtp-user-77')
        ->and(json_encode($audit->properties))->not->toContain('Sup3r-Secret!')->not->toContain('smtp-user-77')
        ->and($audit->properties['after']['smtp_password'])->toBe('[redacted]')
        ->and($audit->properties['after']['smtp_host'])->toBe('smtp.acme.example')
        ->and($audit->organization_id)->toBe($this->org->id)
        ->and($audit->causer_id)->toBe($this->owner->id);
});

it('keeps the stored password when the field is left empty and audits only changes', function (): void {
    actingInAdmin($this->owner, $this->org);
    MailSetting::factory()->withSmtp()->create();

    Livewire::test(MailSettings::class)
        ->assertFormSet(['smtp_password' => null, 'smtp_host' => 'smtp.example.com'])
        ->fillForm(['smtp_host' => 'mail.example.org'])
        ->call('save')
        ->assertHasNoFormErrors();

    $audit = Activity::query()->where('event', 'mail_settings.updated')->sole();
    expect(MailSetting::query()->sole()->smtp_password)->toBe('smtp-secret')
        ->and($audit->properties['before'])->toBe(['smtp_host' => 'smtp.example.com'])
        ->and($audit->properties['after'])->toBe(['smtp_host' => 'mail.example.org']);

    Livewire::test(MailSettings::class)->fillForm(['smtp_password' => 'rotated'])->call('save');
    $rotation = Activity::query()->where('event', 'mail_settings.updated')->latest('id')->first();
    expect($rotation->properties['after'])->toBe(['smtp_password' => '[redacted]'])
        ->and(MailSetting::query()->sole()->smtp_password)->toBe('rotated');
});

it('validates the SMTP fields and refuses private hosts', function (): void {
    actingInAdmin($this->owner, $this->org);

    Livewire::test(MailSettings::class)
        ->fillForm(['smtp_enabled' => true, 'smtp_host' => null, 'smtp_from_address' => 'not-an-email'])
        ->call('save')
        ->assertHasFormErrors(['smtp_host' => 'required', 'smtp_from_address']);

    config(['messaging.smtp.allow_private_hosts' => false]);
    Livewire::test(MailSettings::class)
        ->fillForm(['smtp_enabled' => true, 'smtp_host' => '192.168.1.10', 'smtp_port' => 25, 'smtp_encryption' => 'none', 'smtp_from_address' => 'office@acme.example'])
        ->call('save')
        ->assertHasFormErrors(['smtp_host']);

    expect(MailSetting::query()->count())->toBe(0);
});

it('sends a test email to the current user with the saved settings', function (): void {
    Mail::fake();
    actingInAdmin($this->owner, $this->org);
    MailSetting::factory()->create(['sender_name' => 'Acme Service', 'reply_to_address' => 'office@acme.example']);

    Livewire::test(MailSettings::class)->callAction('sendTestEmail')->assertHasNoActionErrors()->assertNotified();

    Mail::assertSent(TestMail::class, fn (TestMail $mail): bool => $mail->hasTo($this->owner->email)
        && $mail->hasFrom((string) config('mail.from.address'), 'Acme Service') && $mail->hasReplyTo('office@acme.example'));
    expect(MailMessage::query()->sole())->recipient_email->toBe($this->owner->email)->mailable_class->toBe(TestMail::class);
});
