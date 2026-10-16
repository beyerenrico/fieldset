<?php

declare(strict_types=1);

namespace Tests\Feature\Mail;

use App\Domain\Identity\Models\Organization;
use App\Domain\Identity\Tenancy\TenantContext;
use App\Domain\Mail\Delivery\DocumentFile;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Mime\Email;

/** Shared fixtures for the mail tests. */
final class MailFixtures
{
    /** Own fake disk name: Storage::fake('local') is shared between concurrent test runs (LEARNINGS #23). */
    public const string DISK = 'mail-tests';

    /** Smallest valid PNG (1×1). */
    public const string PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

    public static function organization(string $name = 'Acme GmbH'): Organization
    {
        $organization = makeOrganization(name: $name);
        app(TenantContext::class)->set($organization);

        return $organization;
    }

    public static function fakeDisk(): void
    {
        Storage::fake(self::DISK);
        config(['documents.disk' => self::DISK]);
    }

    public static function document(int $size = 1024, string $name = 'LS-2026-0001.pdf', ?string $submissionUuid = null): DocumentFile
    {
        $path = 'renditions/'.bin2hex(random_bytes(6)).'.pdf';
        Storage::disk(self::DISK)->put($path, str_repeat('%', $size));

        return new DocumentFile(self::DISK, $path, $name, 'application/pdf', $size, $submissionUuid);
    }

    /** Messages captured by the "array" mailer (the instance default in tests). */
    public static function arrayTransport(): ArrayTransport
    {
        $transport = Mail::mailer('array')->getSymfonyTransport();
        assert($transport instanceof ArrayTransport);

        return $transport;
    }

    public static function lastSentEmail(): Email
    {
        $message = self::arrayTransport()->messages()->last()?->getOriginalMessage();
        assert($message instanceof Email);

        return $message;
    }
}
