<?php

declare(strict_types=1);

use Illuminate\Mail\Mailer;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;

/*
 * M11 gate (ADR 0080 amendment): fieldset renders MAIL_REQUIRE_TLS=true for `--smtp-encryption starttls` and
 * MAIL_AUTO_TLS=false for `none`; config/mail.php hands both to Symfony's EsmtpTransportFactory (`require_tls`,
 * `auto_tls` DSN options), so a server that hides STARTTLS gets no AUTH and no mail.
 */

/** The instance mailer's config as config/mail.php reads it with these environment variables. */
function instanceMailerConfig(array $env): array
{
    foreach ($env as $key => $value) {
        $_SERVER[$key] = $_ENV[$key] = $value;
    }

    try {
        return (require base_path('config/mail.php'))['mailers']['smtp'];
    } finally {
        foreach (array_keys($env) as $key) {
            unset($_SERVER[$key], $_ENV[$key]);
        }
    }
}

function instanceTransport(array $config): EsmtpTransport
{
    config(['mail.mailers.smtp' => [...$config, 'host' => 'smtp.example.com', 'port' => 587]]);
    Mail::purge('smtp');
    $mailer = Mail::mailer('smtp');
    assert($mailer instanceof Mailer);
    $transport = $mailer->getSymfonyTransport();
    assert($transport instanceof EsmtpTransport);

    return $transport;
}

it('requires TLS when MAIL_REQUIRE_TLS is true', function (): void {
    $transport = instanceTransport(instanceMailerConfig(['MAIL_REQUIRE_TLS' => 'true']));

    expect($transport->isTlsRequired())->toBeTrue()->and($transport->isAutoTls())->toBeTrue();
});

it('never starts STARTTLS when MAIL_AUTO_TLS is false (a plain relay)', function (): void {
    $transport = instanceTransport(instanceMailerConfig(['MAIL_AUTO_TLS' => 'false']));

    expect($transport->isAutoTls())->toBeFalse()->and($transport->isTlsRequired())->toBeFalse();
});

it('keeps Symfony\'s defaults without the variables (dev stack, CI, the stack file)', function (): void {
    $transport = instanceTransport(instanceMailerConfig([]));

    expect($transport->isTlsRequired())->toBeFalse()->and($transport->isAutoTls())->toBeTrue();
});
