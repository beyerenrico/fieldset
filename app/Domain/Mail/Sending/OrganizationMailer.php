<?php

declare(strict_types=1);

namespace App\Domain\Mail\Sending;

use App\Domain\Identity\Models\Organization;
use App\Domain\Identity\Tenancy\TenantContext;
use App\Domain\Identity\Tenancy\TenantNotSetException;
use App\Domain\Mail\Enums\SmtpEncryption;
use App\Domain\Mail\Models\MailSetting;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\SentMessage;
use Illuminate\Support\Facades\Mail;

/**
 * Sends a mailable as the current organization (§8.8): applies the sender name and reply-to and
 * picks the transport at send time — the organization's own SMTP server when configured, else the
 * instance mailer. The From address stays on the instance domain unless the own SMTP is used.
 */
final class OrganizationMailer
{
    public const string MAILER_PREFIX = 'organization-smtp-';

    public function __construct(private readonly TenantContext $tenant, private readonly SmtpHostGuard $guard) {}

    public function send(Mailable $mailable): ?SentMessage
    {
        $organization = $this->tenant->organization() ?? throw TenantNotSetException::make();
        $settings = MailSetting::current();

        $this->applySender($mailable, $organization, $settings);

        return Mail::mailer($this->mailerFor($settings))->send($mailable);
    }

    public function applySender(Mailable $mailable, Organization $organization, ?MailSetting $settings): void
    {
        $name = filled($settings?->sender_name) ? (string) $settings->sender_name : $organization->name;
        $address = $settings?->usesOwnSmtp() === true ? (string) $settings->smtp_from_address : (string) config('mail.from.address');

        $mailable->from = [];
        $mailable->replyTo = [];
        $mailable->from($address, $name);

        if (filled($settings?->reply_to_address)) {
            $mailable->replyTo((string) $settings->reply_to_address, $name);
        }
    }

    /** Mailer name for the organization's own SMTP server, or null for the instance default. */
    public function mailerFor(?MailSetting $settings): ?string
    {
        if ($settings === null || ! $settings->usesOwnSmtp()) {
            return null;
        }

        $this->guard->assertAllowed((string) $settings->smtp_host);

        $name = self::MAILER_PREFIX.$settings->organization_id;
        config(["mail.mailers.{$name}" => $this->smtpConfig($settings)]);
        Mail::purge($name); // settings may have changed since a long-running worker built it

        return $name;
    }

    /** @return array<string, mixed> */
    public function smtpConfig(MailSetting $settings): array
    {
        $encryption = $settings->smtp_encryption ?? SmtpEncryption::StartTls;
        $username = filled($settings->smtp_username) ? $settings->smtp_username : null;

        return [
            'transport' => 'smtp',
            'scheme' => $encryption->scheme(),
            'host' => $settings->smtp_host,
            'port' => $settings->smtp_port ?? $encryption->defaultPort(),
            'username' => $username,
            'password' => $username === null ? null : $settings->smtp_password,
            'timeout' => (int) config('messaging.smtp.timeout'),
            'auto_tls' => $encryption !== SmtpEncryption::None,
            'require_tls' => $encryption === SmtpEncryption::StartTls,
            'local_domain' => config('mail.mailers.smtp.local_domain'),
        ];
    }

    /** Removes the current organization's SMTP credentials from an error text before it is stored. */
    public function scrub(string $error): string
    {
        $settings = MailSetting::current();
        $secrets = array_filter([$settings?->smtp_password, $settings?->smtp_username], filled(...));

        return $secrets === [] ? $error : str_replace($secrets, '[redacted]', $error);
    }
}
