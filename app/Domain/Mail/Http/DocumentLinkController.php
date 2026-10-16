<?php

declare(strict_types=1);

namespace App\Domain\Mail\Http;

use App\Domain\Mail\Delivery\DownloadDocumentLink;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Public, rate-limited download of a mailed document link (§8.8). */
final class DocumentLinkController
{
    public function __invoke(string $token, DownloadDocumentLink $download): StreamedResponse
    {
        return $download->handle($token);
    }
}
