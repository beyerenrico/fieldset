<?php

declare(strict_types=1);

namespace App\Domain\Instance\Restore;

use SensitiveParameter;

/**
 * What `fieldset restore` (and the drill) was asked for (plan U17, ADR 0085). The passphrase and the key file's keys are
 * secrets: they never reach the journal, whose context holds only the backup, the flags, the identity file's path and
 * where the passphrase and the keys came from (`--passphrase-file` path or `--passphrase-stdin`, the `--app-key-file`
 * path), so the resume command names every input again (ResumeCommand, M12 gate O2).
 * `withoutFiles`: restore a backup that carries no storage archive (M12 gate D4); the previous volume is kept.
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
        public bool $withoutFiles = false,
        public ?string $passphraseFile = null,
        public bool $passphraseStdin = false,
        public ?string $keyFilePath = null,
    ) {}

    /** @return array<string, string|bool|null> the journal context */
    public function toContext(): array
    {
        return ['backup' => $this->backup, ...$this->inputs(), 'without_files' => $this->withoutFiles];
    }

    /**
     * The non-secret description of the inputs: the unauthenticated switch, the identity file, the passphrase's source and
     * the APP key file (ResumeCommand rebuilds the flags from it).
     *
     * @return array<string, string|bool|null>
     */
    public function inputs(): array
    {
        return ['accept_unauthenticated' => $this->acceptUnauthenticated, 'identity_file' => $this->identityFile, 'key_file' => $this->keyFile instanceof AppKeys,
            'passphrase_file' => $this->passphraseFile, 'passphrase_stdin' => $this->passphraseStdin, 'app_key_file' => $this->keyFile instanceof AppKeys ? $this->keyFilePath : null];
    }

    /** @return array<string, string> */
    public function __debugInfo(): array
    {
        return ['backup' => $this->backup];
    }
}
