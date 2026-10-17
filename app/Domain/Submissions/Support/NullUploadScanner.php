<?php

declare(strict_types=1);

namespace App\Domain\Submissions\Support;

/** The default: uploads are not scanned (the other §11 checks still apply). */
final class NullUploadScanner implements UploadScanner
{
    public function scan(string $bytes): UploadScanResult
    {
        return UploadScanResult::notScanned();
    }
}
