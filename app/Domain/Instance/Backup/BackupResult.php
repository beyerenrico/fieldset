<?php

declare(strict_types=1);

namespace App\Domain\Instance\Backup;

/**
 * A complete backup as BackupWriter left it (ADR 0084): its name and size, whether it is encrypted or carries secrets,
 * the referenced files missing from storage (recorded in the manifest, not a failure), and warnings.
 */
final readonly class BackupResult
{
    /**
     * @param  list<string>  $missing  the first referenced files that were not in storage
     * @param  list<array{code: string, message: string}>  $warnings
     * @param  list<string>  $fingerprints  APP key fingerprints the HMAC key derives from
     */
    public function __construct(
        public BackupName $name,
        public string $path,
        public int $bytes,
        public bool $encrypted,
        public bool $includesSecrets,
        public int $referenced,
        public int $missingCount,
        public array $missing,
        public array $warnings,
        public array $fingerprints,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'backup' => $this->name->name(), 'kind' => $this->name->kind, 'created_at' => $this->name->at->toIso8601ZuluString(),
            'bytes' => $this->bytes, 'encrypted' => $this->encrypted, 'includes_secrets' => $this->includesSecrets,
            'files' => ['referenced' => $this->referenced, 'missing' => $this->missingCount, 'missing_sample' => $this->missing],
            'app_key_fingerprints' => $this->fingerprints, 'warnings' => $this->warnings,
        ];
    }
}
