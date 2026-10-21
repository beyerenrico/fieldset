<?php

declare(strict_types=1);

namespace App\Domain\Instance\Config\Appliers;

use App\Domain\Instance\Backup\KeySetup;
use App\Domain\Instance\Config\ConfigChange;
use App\Domain\Instance\Config\ConfigRequest;
use App\Domain\Instance\Config\KeyFileRequest;
use App\Domain\Instance\Config\SettingApplier;
use App\Domain\Instance\Config\SettingHooks;
use App\Domain\Instance\Docker\ComposeProject;
use App\Domain\Instance\HostFacts;
use App\Domain\Instance\Output\InvalidInput;
use App\Domain\Instance\State\InstanceDirectory;
use App\Domain\Instance\State\InstanceState;

/**
 * `fieldset config set backup-encryption on|off` (R32, R40, KTD17, ADR 0084).
 *
 * - `on --identity-out <path> --passphrase-file <file>|--passphrase-stdin`: the preflight generates an age key pair,
 *   writes the passphrase-protected identity outside the instance directory and decrypt-tests it (KeySetup); the target
 *   state records only the recipient. Every later backup, scheduled or manual, is encrypted to it; the backup service is
 *   recreated with it. Already on: `changed: false` (key rotation is a later step, see ADR 0084).
 * - `off`: the recipient goes, later backups are plain again; existing encrypted backups stay as they are.
 *
 * A change that fails after the preflight is reverted like every key; the identity file it wrote stays (it decrypts
 * nothing that was written with it).
 */
final readonly class BackupEncryptionApplier implements SettingApplier, SettingHooks
{
    public const string KEY = 'backup-encryption';

    public function __construct(private InstanceDirectory $directory, private KeySetup $keys) {}

    public function plan(InstanceState $state, ConfigRequest $request): ConfigChange
    {
        $value = strtolower(trim($request->value));
        $enabled = $state->recipients() !== [];
        $from = $enabled ? 'on' : 'off';
        if ($value === 'off') {
            return new ConfigChange(self::KEY, $state, $state->withRecipients([]), $from, 'off');
        }
        if ($value !== 'on') {
            throw new InvalidInput('backup-encryption takes `on` or `off`.', 'invalid_value', 'For example `fieldset config set backup-encryption on --identity-out ~/fieldset-backup-identity.age --passphrase-file ~/passphrase.txt`.');
        }
        if ($enabled) {
            return new ConfigChange(self::KEY, $state, $state, $from, 'on');
        }
        if ($request->identityOut === null || $request->passphrase === null) {
            throw new InvalidInput('Turning backup encryption on needs --identity-out <path> and the passphrase from --passphrase-file or --passphrase-stdin.', 'identity_required', 'The identity file and its passphrase are the only way to read encrypted backups: store them apart, outside the instance directory.');
        }
        KeySetup::checkPath($request->identityOut, $this->directory);

        return new ConfigChange(self::KEY, $state, $state, $from, 'on', keyFile: new KeyFileRequest($request->identityOut, $request->passphrase));
    }

    public function preflight(ConfigChange $change, HostFacts $facts): ConfigChange
    {
        if (! $change->keyFile instanceof KeyFileRequest || $change->after->recipients() !== []) {
            return $change;
        }
        KeySetup::checkPath($change->keyFile->path, $this->directory);
        $recipient = $this->keys->create($change->keyFile->path, $change->keyFile->passphrase);

        return $change->withAfter($change->after->withRecipients([$recipient]), 'on')
            ->withWarning('backup_identity_written', "The backup identity is in {$change->keyFile->path}, protected by your passphrase.", 'Keep the file and the passphrase apart and safe: without both, encrypted backups cannot be read.');
    }

    public function verifySeconds(): int
    {
        return 0;
    }

    public function verify(ConfigChange $change, ConfigRequest $request, ComposeProject $project): array
    {
        return [];
    }

    public function completed(ConfigChange $change, ConfigRequest $request, ComposeProject $project): array
    {
        $recipients = $change->after->recipients();

        return ['data' => ['encryption' => ['enabled' => $recipients !== [], 'recipients' => $recipients, 'identity' => $request->identityOut]], 'warnings' => []];
    }
}
