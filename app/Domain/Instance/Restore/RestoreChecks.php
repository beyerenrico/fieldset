<?php

declare(strict_types=1);

namespace App\Domain\Instance\Restore;

use App\Domain\Instance\Backup\BackupChecksums;
use App\Domain\Instance\InstanceException;
use App\Domain\Instance\Output\ExitCode;
use App\Domain\Instance\Output\InvalidInput;
use App\Domain\Instance\Release\ReleaseVersion;
use Throwable;

/**
 * fsa's verdict on a prepared backup (R41, R42, R67, AE8, KTD18, plan U17, ADR 0085), before anything changes. In this
 * order: damaged (checksums) and unsafe (the dump's table of contents) are always refused (exit 2); a newer migration
 * set, or a version below the release's oldest upgrade source, is incompatible (exit 7, AE8); an archive whose HMAC
 * no key from outside it verifies needs `--accept-unauthenticated` (exit 2); every APP key the backup lists must be
 * known (the instance's, a key file's or, for `--from-backup`, the backup's own secrets), else exit 2. An older backup
 * restored in place is migrated by the upgrade child (U18, ADR 0085 amendment) before its finish step.
 */
final readonly class RestoreChecks
{
    public const string IN_PLACE = 'restore';

    public const string DRILL = 'drill';

    public const string FROM_BACKUP = 'from-backup';

    /**
     * @param  AppKeys  $known  keys that may decrypt the backup's data (and, from outside the archive, verify its HMAC)
     * @return list<array{0: string, 1: string, 2: string|null}> warnings
     *
     * @throws InstanceException
     */
    public static function assert(PreparedBackup $backup, string $purpose, bool $acceptUnauthenticated, AppKeys $known, ?string $upgradesFrom): array
    {
        self::assertIntact($backup);
        self::assertCompatible($backup, $upgradesFrom);
        $warnings = self::assertAuthenticated($backup, $acceptUnauthenticated);
        $missing = array_values(array_diff($backup->fingerprints(), $known->fingerprints()));
        if ($missing !== []) {
            throw new InvalidInput(
                'The backup\'s data is encrypted under an APP key this instance does not have (fingerprint '.implode(', ', $missing).').',
                'app_key_unknown',
                'Pass that key with --app-key-file <file> (the old instance\'s env/app.env works); without it encrypted values cannot be read.',
                ['fingerprints' => $missing],
            );
        }
        if ($backup->strays !== []) {
            $warnings[] = ['backup_strays_ignored', 'Files that are not part of the backup set were ignored: '.implode(', ', $backup->strays).'.', null];
        }

        return $warnings;
    }

    /**
     * An in-place restore takes only this instance's own backups (review A3): the R64 carry matches rows by their IDs,
     * so another instance's users would inherit this one's revocations and erasures. A 1.0 archive names no instance; it
     * counts as this instance's when the APP_KEY in its `env` is one of the instance's own keys. The backup `fsa adopt`
     * wrote holds no key (U19, ADR 0087): its manifest's first key fingerprint counts only for exactly that set, by its
     * name and the digest of its SHA256SUMS as the adopt recorded them (review R1: the manifest is unauthenticated, a
     * fingerprint alone is no proof). Everything else goes to a new directory with `install.sh --dir <new directory> --from-backup <backup>`.
     *
     * @param  AppKeys  $inside  the keys the archive carries (BackupIntake::insideKeys)
     * @param  array{name: string, sums: string}|null  $adoptBackup  the adopt backup this instance made (AdoptedInstance::backupIdentity)
     *
     * @throws InstanceException exit 2
     */
    public static function assertSameInstance(PreparedBackup $backup, string $uuid, AppKeys $own, AppKeys $inside, ?array $adoptBackup = null): void
    {
        if ($backup->isLegacy()) {
            $key = $inside->fingerprints()[0] ?? (self::isAdoptBackup($backup, $adoptBackup) ? $backup->fingerprints()[0] ?? null : null);
            if ($key !== null && in_array($key, $own->fingerprints(), true)) {
                return;
            }
            $made = null;
            $why = $key === null ? 'it is a 1.0 backup without its env file (or without an APP key in it), so nothing says which instance made it' : 'it is a 1.0 backup made under an APP key this instance does not hold';
        } else {
            $instance = $backup->manifest['instance'] ?? null;
            $made = is_array($instance) && is_string($instance['uuid'] ?? null) ? $instance['uuid'] : null;
            if ($made === $uuid) {
                return;
            }
            $why = $made === null ? 'its manifest names no instance' : "it was made by the instance {$made}";
        }
        throw new InvalidInput(
            "This backup does not belong to this instance: {$why}.",
            'backup_other_instance',
            'Install it into a new directory with `install.sh --dir <new directory> --from-backup <backup>`; restoring it here would match its accounts to this instance\'s by number. Nothing was changed.',
            ['backup_instance' => $made ?? null, 'instance' => $uuid],
        );
    }

    /** @param  array{name: string, sums: string}|null  $adoptBackup */
    private static function isAdoptBackup(PreparedBackup $backup, ?array $adoptBackup): bool
    {
        $sums = $backup->set.'/'.BackupChecksums::SUMS;

        return $adoptBackup !== null && $backup->name === $adoptBackup['name'] && is_file($sums) && hash_equals($adoptBackup['sums'], (string) hash_file('sha256', $sums));
    }

    /** @throws InstanceException */
    private static function assertIntact(PreparedBackup $backup): void
    {
        if ($backup->problems !== []) {
            throw new InvalidInput('The backup is damaged: '.implode('; ', array_slice($backup->problems, 0, 5)).'.', 'backup_damaged', 'Use another backup; a changed or missing file is never restored.', ['problems' => $backup->problems]);
        }
        if ($backup->dump['rejected'] !== []) {
            throw new InvalidInput('The dump holds objects a backup of this app never has: '.implode('; ', array_slice($backup->dump['rejected'], 0, 3)).'.', 'backup_unsafe', 'Nothing was restored. Use a backup written by fsa or by scripts/backup.sh of this app.', ['rejected' => $backup->dump['rejected']]);
        }
        if ($backup->migrations['relation'] === MigrationSet::NONE) {
            throw new InvalidInput('The dump has no migrations table: it is not a backup of this app.', 'not_an_app_backup', 'Use a backup written by fsa or by scripts/backup.sh.');
        }
    }

    /** @throws InstanceException */
    private static function assertCompatible(PreparedBackup $backup, ?string $upgradesFrom): void
    {
        if ($backup->migrations['relation'] === MigrationSet::NEWER) {
            throw new InstanceException(
                'The backup was made by a newer version: it ran migrations this version does not know ('.implode(', ', array_slice($backup->migrations['unknown'], 0, 3)).').',
                ExitCode::Incompatible, 'backup_newer', 'Restore it with the version that made it (or a newer one): `fsa upgrade` first; nothing was changed.', false, ['unknown_migrations' => $backup->migrations['unknown']],
            );
        }
        if (! $backup->isLegacy() && $upgradesFrom !== null && self::below($backup->version(), $upgradesFrom)) {
            throw new InstanceException("The backup was made by {$backup->version()}, older than the oldest version this release upgrades from ({$upgradesFrom}).", ExitCode::Incompatible, 'backup_too_old', 'Restore it into an instance of an intermediate release first; nothing was changed.');
        }
    }

    /**
     * @return list<array{0: string, 1: string, 2: string|null}>
     *
     * @throws InstanceException
     */
    private static function assertAuthenticated(PreparedBackup $backup, bool $accept): array
    {
        if ($backup->authenticated()) {
            return [];
        }
        $why = match ($backup->hmac) {
            'mismatch' => 'its HMAC does not match any APP key from outside the archive (another instance\'s backup, or it was changed)',
            default => $backup->isLegacy() ? 'the 1.0 format carries no HMAC' : 'it carries no HMAC',
        };
        if (! $accept) {
            throw new InvalidInput("The backup is not authenticated: {$why}.", 'backup_unauthenticated', 'Pass the APP key it was made with (--app-key-file), or --accept-unauthenticated if you trust where it comes from; it is then restored by a role that owns only the scratch database.', ['hmac' => $backup->hmac]);
        }

        return [['backup_unauthenticated', "The backup is not authenticated ({$why}); it was accepted with --accept-unauthenticated.", null]];
    }

    private static function below(?string $version, string $floor): bool
    {
        try {
            return $version !== null && ReleaseVersion::parse($version)->compare(ReleaseVersion::parse($floor)) < 0;
        } catch (Throwable) {
            return false; // a version this release cannot parse is judged by its migration set alone
        }
    }
}
