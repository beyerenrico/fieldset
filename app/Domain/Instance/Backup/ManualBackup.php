<?php

declare(strict_types=1);

namespace App\Domain\Instance\Backup;

use App\Domain\Instance\Console\FsaContext;
use App\Domain\Instance\Docker\ComposeProject;
use App\Domain\Instance\Install\InstallRecord;
use App\Domain\Instance\InstanceException;
use App\Domain\Instance\Lifecycle\OneOffResult;
use App\Domain\Instance\Operations\ContainerIdentity;
use App\Domain\Instance\Operations\InstanceLock;
use App\Domain\Instance\Output\CommandResult;
use App\Domain\Instance\Output\ExitCode;
use App\Domain\Instance\Output\InvalidInput;
use App\Domain\Instance\State\EnvFiles;
use App\Domain\Instance\State\InstanceDirectory;
use App\Domain\Instance\State\InstanceState;

/**
 * `fsa backup` without the key set-up (R38, R40, R67, ADR 0084): under the instance lock, the backup service's entry
 * point runs once (BackupRunner) and writes `backups/manual-<time>/`, encrypted when the instance has recipients. With
 * `--include-secrets` the APP keys and the SMTP account from env/app.env go into the backup on the one-off's stdin
 * (never an argument); that needs encryption (exit 2 otherwise). The result names the backup, its size, whether it is
 * encrypted, the APP_KEY fingerprint its HMAC derives from, and the referenced files missing from storage.
 */
final readonly class ManualBackup
{
    public const string OPERATION = 'backup';

    /** env/app.env keys `--include-secrets` carries (R42: what another host needs; the rest is re-generated there). */
    private const array SECRET_KEYS = ['APP_KEY', 'APP_PREVIOUS_KEYS', 'MAIL_USERNAME', 'MAIL_PASSWORD'];

    public function __construct(
        private InstanceDirectory $directory,
        private InstanceLock $lock,
        private ContainerIdentity $identity,
        private BackupRunner $runner,
    ) {}

    public function run(FsaContext $context, bool $includeSecrets): CommandResult
    {
        $state = $this->installedState();
        if ($includeSecrets && $state->recipients() === []) {
            throw new InvalidInput('--include-secrets needs an encrypted backup.', 'encryption_required', 'Add --encrypt (with --identity-out and --passphrase-file) or run `fsa config set backup-encryption on` first.');
        }
        $handle = $this->lock->acquire($this->identity->holder('fsa', $context->facts->callerUid()), self::OPERATION);

        try {
            $context->progress->phase('backup', 'Writing a manual backup');
            $outcome = $this->runner->backup(ComposeProject::forInstance($state, $this->directory), BackupName::MANUAL, $handle->record->lockId, $includeSecrets ? $this->secrets() : null);
        } finally {
            $this->lock->release($handle);
        }

        return self::result($outcome, $this->directory);
    }

    /** The envelope of a backup one-off (also for the safety-backup entry point's callers). */
    public static function result(OneOffResult $outcome, InstanceDirectory $directory): CommandResult
    {
        if (! $outcome->ok) {
            $code = $outcome->errorCode();
            $exit = match ($code) {
                'low_disk_space' => ExitCode::PreflightFailed,
                'lock_mismatch' => ExitCode::AbortedUnchanged,
                'encryption_required', 'secrets_missing', 'invalid_kind' => ExitCode::InvalidInput,
                default => ExitCode::HealthCheckFailed,
            };

            return CommandResult::failed('backup', $exit, $code, 'The backup failed: '.$outcome->errorMessage(), $code === 'low_disk_space'
                ? 'Free space on the disk holding the instance directory (old backups in backups/), then run `fsa backup` again.'
                : 'Read `fsa logs backup` and `fsa status`, fix the cause, then run `fsa backup` again; nothing was left in backups/.', $exit === ExitCode::HealthCheckFailed, ['output' => $outcome->output]);
        }
        $run = $outcome->data;
        $details = is_array($run['result'] ?? null) ? $run['result'] : [];
        $name = (string) ($run['backup'] ?? '');
        $result = CommandResult::ok('backup', [
            'backup' => $name, 'path' => $directory->root.'/'.InstanceDirectory::BACKUPS_DIR.'/'.$name, 'kind' => $details['kind'] ?? null,
            'created_at' => $details['created_at'] ?? null, 'bytes' => $run['bytes'] ?? null, 'encrypted' => ($run['encrypted'] ?? false) === true,
            'includes_secrets' => ($details['includes_secrets'] ?? false) === true, 'app_key_fingerprint' => $details['app_key_fingerprints'][0] ?? null,
            'files' => $details['files'] ?? null, 'free_bytes' => $run['free_bytes'] ?? null,
        ], changed: true);
        foreach (is_array($details['warnings'] ?? null) ? $details['warnings'] : [] as $warning) {
            if (is_array($warning) && is_string($warning['code'] ?? null)) {
                $result = $result->withWarning($warning['code'], (string) ($warning['message'] ?? ''), $warning['code'] === 'files_missing' ? 'manifest.json in the backup lists them; `fsa status` shows the newest backups.' : null);
            }
        }

        return $result->withWarning('backup_on_host', 'Backups stay in the instance directory on this host.', 'Copy backups/ to another machine or storage regularly.');
    }

    /** @return string `secrets.env` from env/app.env (only the keys another host needs) */
    private function secrets(): string
    {
        $app = new EnvFiles($this->directory)->read('app');
        $secrets = array_filter(array_intersect_key($app, array_flip(self::SECRET_KEYS)), static fn (string $value): bool => $value !== '');
        if (($secrets['APP_KEY'] ?? '') === '') {
            throw new InstanceException('env/app.env holds no APP_KEY.', ExitCode::PreflightFailed, 'app_key_missing');
        }

        return EnvFiles::encode($secrets);
    }

    private function installedState(): InstanceState
    {
        $state = $this->directory->hasState() ? $this->directory->state() : null;
        if (! $state instanceof InstanceState || ! InstallRecord::isInstalled($state)) {
            throw new InvalidInput('The instance is not installed yet.', 'not_installed', 'Run `fsa install` first (it resumes an unfinished install).');
        }

        return $state;
    }
}
