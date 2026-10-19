<?php

declare(strict_types=1);

namespace App\Domain\Instance\Restore;

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
 * known (the instance's, a key file's or, for `--from-backup`, the backup's own secrets), else exit 2; an older backup
 * restored in place needs the upgrade child (U18) and exits 7 until then.
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
        self::assertCompatible($backup, $purpose, $upgradesFrom);
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
    private static function assertCompatible(PreparedBackup $backup, string $purpose, ?string $upgradesFrom): void
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
        if ($purpose === self::IN_PLACE && $backup->migrations['relation'] === MigrationSet::OLDER) {
            throw new InstanceException(
                'The backup is older than this version ('.$backup->migrations['pending'].' migration(s) behind): restoring it in place needs the upgrade child, which arrives with the upgrade supervisor.',
                ExitCode::Incompatible, 'upgrade_child_unavailable',
                'Restore it into a new directory with `fsa install --from-backup <backup>` (which migrates it), or check it with `fsa restore --drill`; nothing was changed.',
            );
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
