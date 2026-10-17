<?php

declare(strict_types=1);

namespace App\Domain\Submissions\Support;

use Illuminate\Container\Attributes\Bind;

/**
 * Malware scan of an uploaded file's bytes (BUILD_PROMPT §11: optional ClamAV hook for self-hosters,
 * ADR 0040). The container resolves ConfiguredUploadScanner: no scan unless uploads.clamav.enabled.
 */
#[Bind(ConfiguredUploadScanner::class)]
interface UploadScanner
{
    /** @throws UploadScanFailed when the scanner cannot give a verdict (unreachable, timeout, error reply) */
    public function scan(string $bytes): UploadScanResult;
}
