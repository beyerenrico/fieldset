<?php

declare(strict_types=1);

namespace App\Domain\Instance\Install;

use App\Domain\Instance\Output\InvalidInput;
use App\Domain\Instance\Output\SecretRedactor;
use App\Domain\Instance\Restore\AppKeys;
use App\Domain\Instance\Restore\BackupIntake;
use App\Domain\Instance\Restore\LegacyEnv;
use App\Domain\Instance\Restore\PreparedBackup;
use App\Domain\Instance\State\EnvFiles;
use App\Domain\Instance\State\InstanceDirectory;
use App\Domain\Instance\State\InstanceSecrets;
use App\Domain\Instance\State\InvalidState;
use Closure;

/**
 * The secrets of an instance moved with `fsa install --from-backup` (R42, plan U17, ADR 0085). Its APP_KEY is the backup's
 * current key (the first fingerprint its manifest lists), found in `--app-key-file` or inside the archive (`secrets.env`
 * of `fsa backup --include-secrets`, or the 1.0 `env`); every other key the backup lists becomes APP_PREVIOUS_KEYS, so data
 * encrypted under them still decrypts. The database, Valkey, catcher and backup-role passwords are new.
 */
final readonly class FromBackupKeys
{
    public function __construct(private InstanceDirectory $directory) {}

    /**
     * Writes the secrets unless an earlier attempt did (a resume keeps them: Postgres keeps its first password).
     *
     * @return array{generated: bool, app_key_fingerprint: string, previous_keys: int}
     *
     * @throws InvalidInput exit 2 `app_key_required` without the backup's current key
     */
    public function ensure(PreparedBackup $backup, ?AppKeys $keyFile, SecretRedactor $redactor): array
    {
        return $this->ensureKeys(static fn (): array => self::keys($backup, $keyFile), $redactor);
    }

    /**
     * The same for keys from elsewhere: `fsa adopt` keeps the 1.0 checkout's APP_KEY and APP_PREVIOUS_KEYS (plan U19).
     *
     * @param  Closure(): array{0: string, 1: list<string>}  $keys  the current key and the previous ones, asked only when none are written yet
     * @return array{generated: bool, app_key_fingerprint: string, previous_keys: int}
     */
    public function ensureKeys(Closure $keys, SecretRedactor $redactor): array
    {
        $env = new EnvFiles($this->directory);
        $existing = InstanceSecrets::fromEnvFiles($env);
        if ($existing instanceof InstanceSecrets) {
            return ['generated' => false, 'app_key_fingerprint' => AppKeys::of([$existing->appKey])->fingerprints()[0] ?? '', 'previous_keys' => count($existing->previousKeys)];
        }
        if (($env->read('app')['APP_KEY'] ?? '') !== '') {
            throw new InvalidState('env/app.env holds an APP_KEY without the database password; fsa never generates it again.');
        }
        [$current, $previous] = $keys();
        $secrets = InstanceSecrets::generate()->withAppKey($current)->withPreviousKeys($previous);
        foreach ([$secrets->appKey, ...$secrets->previousKeys, $secrets->dbPassword, $secrets->valkeyPassword, $secrets->catcherPassword] as $value) {
            $redactor->register($value);
        }
        $env->write('app', ['APP_KEY' => $secrets->appKey, 'DB_PASSWORD' => $secrets->dbPassword, 'REDIS_PASSWORD' => $secrets->valkeyPassword,
            ...($secrets->previousKeys === [] ? [] : ['APP_PREVIOUS_KEYS' => implode(',', $secrets->previousKeys)])]);
        $env->write('mailpit', ['MP_UI_AUTH' => InstanceSecrets::CATCHER_USER.':'.$secrets->catcherPassword]);

        return ['generated' => true, 'app_key_fingerprint' => AppKeys::of([$current])->fingerprints()[0] ?? '', 'previous_keys' => count($secrets->previousKeys)];
    }

    /**
     * @return array{0: string, 1: list<string>} the current key and the previous ones
     *
     * @throws InvalidInput
     */
    public static function keys(PreparedBackup $backup, ?AppKeys $keyFile): array
    {
        $known = ($keyFile ?? AppKeys::of([]))->merge(BackupIntake::insideKeys($backup));
        $listed = $backup->fingerprints();
        $current = $listed === [] ? self::legacyKey($backup, $keyFile) : $known->byFingerprint($listed[0]);
        if ($current === null) {
            throw new InvalidInput(
                'The APP key the backup was made with is not known: without it encrypted values cannot be read.',
                'app_key_required',
                'Pass it with --app-key-file <file> (the old instance\'s env/app.env works), or use a backup made with `fsa backup --encrypt --include-secrets`.',
            );
        }
        $previous = $listed === []
            ? array_values(array_filter($known->keys, static fn (string $key): bool => $key !== $current))
            : array_values(array_filter(array_map($known->byFingerprint(...), array_slice($listed, 1)), is_string(...)));

        return [$current, $previous];
    }

    /** A 1.0 backup lists no fingerprint: its `env`'s APP_KEY, else the key file's first key. */
    private static function legacyKey(PreparedBackup $backup, ?AppKeys $keyFile): ?string
    {
        $inside = $backup->legacyEnv ? (LegacyEnv::read((string) @file_get_contents($backup->set.'/env'))['APP_KEY'] ?? null) : null;
        if (is_string($inside) && AppKeys::valid($inside)) {
            return $inside;
        }

        return $keyFile->keys[0] ?? null;
    }
}
