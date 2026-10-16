<?php

declare(strict_types=1);

use App\Domain\Mail\Actions\SaveMailSettings;
use App\Domain\Mail\Enums\SmtpEncryption;
use App\Domain\Mail\Models\MailSetting;
use App\Domain\Mail\Sending\OrganizationMailer;
use App\Domain\Mail\Sending\SmtpHostGuard;
use Illuminate\Mail\Mailer;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mailer\Transport\Smtp\Stream\SocketStream;
use Tests\Feature\Mail\MailFixtures;

/* M3 review #6/#7: SMTP SSRF (DNS rebinding, ports) and credentials bound to the configured server. */

beforeEach(function (): void {
    $this->org = MailFixtures::organization();
});

function smtpStream(string $mailer): SocketStream
{
    $instance = Mail::mailer($mailer);
    assert($instance instanceof Mailer);
    $transport = $instance->getSymfonyTransport();
    assert($transport instanceof EsmtpTransport);
    $stream = $transport->getStream();
    assert($stream instanceof SocketStream);

    return $stream;
}

it('connects to the address it validated and keeps the host name for TLS verification and SNI', function (): void {
    config(['messaging.smtp.allow_private_hosts' => true]);
    $settings = MailSetting::factory()->withSmtp('localhost', 587, SmtpEncryption::StartTls)->create();

    $name = app(OrganizationMailer::class)->mailerFor($settings);

    expect(config("mail.mailers.{$name}.host"))->toBe('127.0.0.1')
        ->and(smtpStream((string) $name)->getHost())->toBe('127.0.0.1')
        ->and(smtpStream((string) $name)->getStreamOptions()['ssl']['peer_name'])->toBe('localhost');
});

it('resolves a host once and refuses it when any address is not public', function (): void {
    $guard = app(SmtpHostGuard::class);

    expect($guard->pinnedAddress('8.8.8.8', 587))->toBe('8.8.8.8')
        ->and($guard->pinnedAddress('[2001:4860:4860::8888]', 465))->toBe('[2001:4860:4860::8888]')
        ->and(fn () => $guard->pinnedAddress('localhost', 587))->toThrow(RuntimeException::class, 'is not allowed')
        ->and(fn () => $guard->pinnedAddress('does-not-exist.invalid', 587))->toThrow(RuntimeException::class, 'is not allowed');
});

it('allows only the SMTP submission ports unless private hosts are enabled', function (int $port, bool $allowed): void {
    $guard = app(SmtpHostGuard::class);

    expect($guard->isAllowedPort($port))->toBe($allowed);
    if (! $allowed) {
        expect(fn () => $guard->pinnedAddress('8.8.8.8', $port))->toThrow(RuntimeException::class, 'port');
    }

    config(['messaging.smtp.allow_private_hosts' => true]);
    expect($guard->isAllowedPort($port))->toBeTrue();
})->with([[25, true], [465, true], [587, true], [2525, true], [22, false], [6379, false], [8080, false]]);

it('rejects other ports when saving the settings', function (): void {
    expect(fn () => app(SaveMailSettings::class)->handle([
        'smtp_enabled' => true, 'smtp_host' => '8.8.8.8', 'smtp_port' => 6379, 'smtp_encryption' => 'starttls',
        'smtp_from_address' => 'office@example.com',
    ]))->toThrow(function (ValidationException $e): void {
        expect($e->errors())->toHaveKey('smtp_port');
    });
});

it('requires the password again when the server, port, user name or encryption change', function (array $change): void {
    config(['messaging.smtp.allow_private_hosts' => true]);
    MailSetting::factory()->withSmtp('smtp.example.com', 587, SmtpEncryption::StartTls)->create();
    $save = fn (array $input) => app(SaveMailSettings::class)->handle([
        'smtp_enabled' => true, 'smtp_host' => 'smtp.example.com', 'smtp_port' => 587, 'smtp_encryption' => 'starttls',
        'smtp_username' => 'mailer', 'smtp_from_address' => 'office@example.com', ...$input,
    ]);

    expect(fn () => $save($change))->toThrow(function (ValidationException $e): void {
        expect($e->errors())->toHaveKey('smtp_password');
    });
    expect(MailSetting::query()->sole()->smtp_host)->toBe('smtp.example.com');

    $save([...$change, 'smtp_password' => 'new-secret']);
    expect(MailSetting::query()->sole()->smtp_password)->toBe('new-secret');
})->with([
    'host' => [['smtp_host' => 'evil.example.net']],
    'port' => [['smtp_port' => 2525]],
    'user name' => [['smtp_username' => 'other-user']],
    'encryption' => [['smtp_encryption' => 'none']],
]);

it('keeps the stored password for other changes and drops it when the server changes without a user name', function (): void {
    config(['messaging.smtp.allow_private_hosts' => true]);
    MailSetting::factory()->withSmtp('smtp.example.com', 587, SmtpEncryption::StartTls)->create();
    $base = ['smtp_enabled' => true, 'smtp_host' => 'smtp.example.com', 'smtp_port' => 587, 'smtp_encryption' => 'starttls', 'smtp_username' => 'mailer', 'smtp_from_address' => 'office@example.com'];

    app(SaveMailSettings::class)->handle([...$base, 'sender_name' => 'Acme Service', 'smtp_from_address' => 'billing@example.com']);
    expect(MailSetting::query()->sole()->smtp_password)->toBe('smtp-secret');

    app(SaveMailSettings::class)->handle([...$base, 'smtp_host' => 'relay.example.net', 'smtp_username' => null]);
    expect(MailSetting::query()->sole()->smtp_password)->toBeNull();
});
