<?php

declare(strict_types=1);

namespace App\Domain\Instance\Config;

use SensitiveParameter;

/**
 * The identity `backup-encryption on` writes (R40, KTD17, ADR 0084): where (`--identity-out`, journaled) and the
 * passphrase that protects it (never journaled, never in a message). It travels with the ConfigChange from the plan to
 * the preflight, which writes and tests the identity.
 */
final readonly class KeyFileRequest
{
    public function __construct(
        public string $path,
        #[SensitiveParameter] public string $passphrase,
    ) {}

    /** @return array<string, string> */
    public function __debugInfo(): array
    {
        return ['path' => $this->path, 'passphrase' => '[redacted]'];
    }
}
