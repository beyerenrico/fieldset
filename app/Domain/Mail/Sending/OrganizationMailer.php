<?php

declare(strict_types=1);

namespace App\Domain\Mail\Sending;

use App\Domain\Audit\ErrorText;
use App\Domain\Identity\Models\Organization;
use App\Domain\Identity\Tenancy\TenantContext;
use App\Domain\Identity\Tenancy\TenantNotSetException;
use App\Domain\Mail\Enums\SmtpEncryption;
use App\Domain\Mail\Models\MailSetting;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailer;
use Illuminate\Mail\SentMessage;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mailer\Transport\Smtp\Stream\SocketStream;

/**
 * Sends a mailable as the current organization (§8.8): applies the sender name and reply-to and
 * picks the transport at send time — the organization's own SMTP server when configured, else the
 * instance mailer. The From address stays on the instance domain unless the own SMTP is used.
 */
final readonly class OrganizationMailer
{
    public const string MAILER_PREFIX = 'organization-smtp-';

    public function __construct(private TenantContext $tenant, private SmtpHostGuard $guard) {}

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

    /**
     * Mailer name for the organization's own SMTP server, or null for the instance default. The host is
     * resolved and validated once (SmtpHostGuard::pinnedAddress); the transport connects to that address
     * and verifies the certificate (and sends SNI) for the configured host name.
     */
    public function mailerFor(?MailSetting $settings): ?string
    {
        if (! $settings instanceof MailSetting || ! $settings->usesOwnSmtp()) {
            return null;
        }

        $host = (string) $settings->smtp_host;
        $address = $this->guard->pinnedAddress($host, $this->port($settings));

        $name = self::MAILER_PREFIX.$settings->organization_id;
        config(["mail.mailers.{$name}" => $this->smtpConfig($settings, $address)]);
        Mail::purge($name); // settings may have changed since a long-running worker built it
        $this->verifyAs(Mail::mailer($name), $host, $address);

        return $name;
    }

    /** @return array<string, mixed> */
    public function smtpConfig(MailSetting $settings, ?string $address = null): array
    {
        $encryption = $settings->smtp_encryption ?? SmtpEncryption::StartTls;
        $username = filled($settings->smtp_username) ? $settings->smtp_username : null;

        return [
            'transport' => 'smtp',
            'scheme' => $encryption->scheme(),
            'host' => $address ?? $settings->smtp_host,
            'port' => $this->port($settings),
            'username' => $username,
            'password' => $username === null ? null : $settings->smtp_password,
            'timeout' => (int) config('messaging.smtp.timeout'),
            'auto_tls' => $encryption !== SmtpEncryption::None,
            'require_tls' => $encryption === SmtpEncryption::StartTls,
            'local_domain' => config('mail.mailers.smtp.local_domain'),
        ];
    }

    private function port(MailSetting $settings): int
    {
        return $settings->smtp_port ?? ($settings->smtp_encryption ?? SmtpEncryption::StartTls)->defaultPort();
    }

    /** TLS peer name (certificate check and SNI) = the configured host, although the socket uses its address. */
    private function verifyAs(mixed $mailer, string $host, string $address): void
    {
        $host = trim($host, '[]');
        if (! $mailer instanceof Mailer || $host === trim($address, '[]')) {
            return; // Mail::fake() or an IP literal: nothing to pin
        }
        $transport = $mailer->getSymfonyTransport();
        $stream = $transport instanceof EsmtpTransport ? $transport->getStream() : null;
        if ($stream instanceof SocketStream) {
            $stream->setStreamOptions(array_replace_recursive($stream->getStreamOptions(), ['ssl' => ['peer_name' => $host]]));
        }
    }

    /** Removes the current organization's SMTP credentials from an error text before it is stored. */
    public function scrub(string $error): string
    {
        $settings = MailSetting::current();

        return ErrorText::scrub($error, [$settings?->smtp_password, $settings?->smtp_username]);
    }
}
