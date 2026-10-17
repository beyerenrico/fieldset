<?php

declare(strict_types=1);

namespace App\Domain\Submissions\Support;

/**
 * Picks the scanner from configuration on every scan: clamd when uploads.clamav.enabled, otherwise none.
 *
 * Settings (defaults in code): uploads.clamav.enabled (false), .host ('clamav'), .port (3310),
 * .timeout (seconds, 10), .fail_open (false: an unreachable scanner rejects the upload with 503).
 */
final class ConfiguredUploadScanner implements UploadScanner
{
    public function scan(string $bytes): UploadScanResult
    {
        if (! (bool) config('uploads.clamav.enabled', false)) {
            return new NullUploadScanner()->scan($bytes);
        }

        return new ClamdUploadScanner(
            host: (string) config('uploads.clamav.host', 'clamav'),
            port: (int) config('uploads.clamav.port', 3310),
            timeoutSeconds: (float) config('uploads.clamav.timeout', 10),
        )->scan($bytes);
    }
}
