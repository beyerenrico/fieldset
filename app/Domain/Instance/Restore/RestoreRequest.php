<?php

declare(strict_types=1);

namespace App\Domain\Instance\Restore;

use SensitiveParameter;

/**
 * What `fsa restore` (and the drill) was asked for (plan U17, ADR 0085). The passphrase and the key file's keys are
 * secrets: they never reach the journal, whose context holds only the backup, the flags and the identity file's path.
 */
final readonly class RestoreRequest
{
    public function __construct(
        public string $backup,
        public bool $acceptUnauthenticated = false,
        public ?string $identityFile = null,
        #[SensitiveParameter] public ?string $passphrase = null,
        #[SensitiveParameter] public ?AppKeys $keyFile = null,
        public int $actorUid = 0,
    ) {}

    /** @return array<string, string|bool|null> the journal context */
    public function toContext(): array
    {
        return ['backup' => $this->backup, 'accept_unauthenticated' => $this->acceptUnauthenticated, 'identity_file' => $this->identityFile, 'key_file' => $this->keyFile instanceof AppKeys];
    }

    /** @return array<string, string> */
    public function __debugInfo(): array
    {
        return ['backup' => $this->backup];
    }
}
