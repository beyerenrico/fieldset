<?php

declare(strict_types=1);

namespace App\Domain\Submissions\Support;

/** Verdict of an UploadScanner: clean, infected (with the signature name), or not scanned at all. */
final readonly class UploadScanResult
{
    private function __construct(
        public bool $clean,
        public bool $scanned,
        public ?string $signature = null,
    ) {}

    public static function clean(): self
    {
        return new self(clean: true, scanned: true);
    }

    public static function infected(string $signature): self
    {
        return new self(clean: false, scanned: true, signature: $signature);
    }

    public static function notScanned(): self
    {
        return new self(clean: true, scanned: false);
    }
}
