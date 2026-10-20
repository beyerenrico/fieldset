<?php

declare(strict_types=1);

namespace App\Domain\Instance\Install;

use App\Domain\Instance\Output\InvalidInput;
use App\Domain\Instance\Restore\AppKeys;
use App\Domain\Instance\Restore\RestoreRequest;
use SensitiveParameter;

/**
 * The backup side of `fsa install --from-backup <backup>` (R42, R60, F6, plan U17, ADR 0085): the backup (any path the
 * launcher mounted), the identity file and its passphrase of an encrypted backup, the APP keys of `--app-key-file`,
 * `--accept-unauthenticated` and `--keep-recipients` (the backup's age recipients are kept only when confirmed, M12 gate
 * S2: whoever can write into a synced backups/ directory could have planted them). The passphrase and the keys never reach
 * the journal; its context holds the path, the identity file's path, whether a key file was given and the paths or
 * switches the passphrase and the keys came from (RestoreRequest::inputs), so the resume command is
 * `fsa install --from-backup <backup>` with the same flags (M12 gate O1).
 */
final readonly class FromBackupRequest
{
    /** Install flags that do not apply: the organization, owner, locale and demo data come from the backup; mail starts captured. */
    public const array NOT_APPLICABLE = ['organization', 'owner_name', 'locale', 'demo', ...InstallMail::SERVER_FLAGS, 'smtp_credentials'];

    public function __construct(
        public string $backup,
        public bool $acceptUnauthenticated = false,
        public ?string $identityFile = null,
        #[SensitiveParameter] public ?string $passphrase = null,
        #[SensitiveParameter] public ?AppKeys $keyFile = null,
        public bool $keepRecipients = false,
        public ?string $passphraseFile = null,
        public bool $passphraseStdin = false,
        public ?string $keyFilePath = null,
    ) {}

    /**
     * Refuses the install flags a move does not take (exit 2 `flag_not_applicable`).
     *
     * @throws InvalidInput
     */
    public static function assertApplicable(InstallRequest $request): void
    {
        $given = array_values(array_intersect(array_keys($request->explicit()), self::NOT_APPLICABLE));
        if ($given === []) {
            return;
        }
        $flags = array_map(static fn (string $key): string => InstallRequest::FLAGS[$key] ?? '--'.$key, $given);

        throw new InvalidInput(
            implode(', ', $flags).' do(es) not apply with --from-backup.',
            'flag_not_applicable',
            'The organization, its users and settings come from the backup, and a moved instance starts with mail captured: leave these flags out, then switch mail with `fsa config set mail smtp …` once the move is complete.',
            ['flags' => $flags],
        );
    }

    public function toRestoreRequest(int $actorUid): RestoreRequest
    {
        return new RestoreRequest($this->backup, $this->acceptUnauthenticated, $this->identityFile, $this->passphrase, $this->keyFile, $actorUid, passphraseFile: $this->passphraseFile, passphraseStdin: $this->passphraseStdin, keyFilePath: $this->keyFilePath);
    }

    /** @return array<string, string|bool|null> */
    public function toContext(): array
    {
        return ['from_backup' => $this->backup, ...$this->toRestoreRequest(0)->inputs(), 'keep_recipients' => $this->keepRecipients];
    }

    /** @return array<string, string> */
    public function __debugInfo(): array
    {
        return ['backup' => $this->backup];
    }
}
