<?php

declare(strict_types=1);

namespace App\Domain\Instance\Restore;

use App\Domain\Instance\Install\ReleaseSource;
use App\Domain\Instance\Release\ManifestSchema;
use App\Domain\Instance\Release\ManifestVerifier;
use App\Domain\Instance\Release\ReleaseManifest;
use App\Domain\Instance\State\InstanceDirectory;
use Throwable;

/**
 * The common start of `fsa restore`, the drill and `fsa install --from-backup` (plan U17, ADR 0085): resolve the backup,
 * prepare it in the socket-less one-off with every APP key from outside the archive, and judge it (RestoreChecks). The
 * keys it may still need are the key file's keys that the backup lists and the instance does not have yet: they become
 * APP_PREVIOUS_KEYS, so data encrypted under them still decrypts after the restore.
 */
final readonly class BackupIntake
{
    public function __construct(private InstanceDirectory $directory, private RestoreSource $source, private RestoreSpace $space) {}

    /**
     * @param  array{image: string, uuid: string, user: string, adopt_backup?: array{name: string, sums: string}|null}  $runAs
     * @param  AppKeys  $own  the instance's APP keys (none for a new instance)
     * @return array{backup: PreparedBackup, warnings: list<array{0: string, 1: string, 2: string|null}>, add: list<string>}
     */
    public function take(array $runAs, RestoreRequest $request, string $purpose, string $id, AppKeys $own, ?string $version, bool $mountedElsewhere = false): array
    {
        $path = $this->source->resolve($request->backup, $mountedElsewhere);
        $outside = $request->keyFile instanceof AppKeys ? $own->merge($request->keyFile) : $own;
        // Only the current APP_KEY and keys the operator passes authenticate: a previous key may be retired because it leaked.
        $authenticating = AppKeys::of(array_slice($own->keys, 0, 1))->merge($request->keyFile ?? AppKeys::of([]));
        $scratch = $this->source->scratch($id); // a killed run's copy goes before the room for the new one is counted
        $free = $this->space->assertScratch($path);
        $backup = $this->source->prepare($runAs, $path, $scratch, $authenticating, $request->identityFile, $request->passphrase);
        // A new host (`--from-backup`) may decrypt with the keys the backup carries; the HMAC never uses them.
        $known = $purpose === RestoreChecks::FROM_BACKUP ? $outside->merge(self::insideKeys($backup)) : $outside;
        $warnings = RestoreChecks::assert($backup, $purpose, $request->acceptUnauthenticated, $known, $this->upgradesFrom($version));
        if ($free === null) { // restore, drill and --from-backup alike (review A12)
            $warnings[] = ['disk_space_unknown', 'The free disk space could not be read, so it was not checked.', 'Check `df` on the host before the next restore.'];
        }
        if ($purpose === RestoreChecks::IN_PLACE) {
            RestoreChecks::assertSameInstance($backup, $runAs['uuid'], $own, self::insideKeys($backup), $runAs['adopt_backup'] ?? null);
        }
        $files = $backup->storage === null && $purpose !== RestoreChecks::DRILL ? RestoreChecks::withoutFiles($purpose, $request->withoutFiles) : null;
        if ($files !== null) {
            $warnings[] = $files;
        }
        $add = array_values(array_filter($known->keys, static fn (string $key): bool => in_array(AppKeys::of([$key])->fingerprints()[0], $backup->fingerprints(), true) && ! in_array($key, $own->keys, true)));

        return ['backup' => $backup, 'warnings' => $warnings, 'add' => $add];
    }

    /** The APP keys inside the set: `secrets.env` of an fsa backup with secrets, the `env` of a 1.0 backup. */
    public static function insideKeys(PreparedBackup $backup): AppKeys
    {
        $file = match (true) {
            $backup->secrets => $backup->set.'/secrets.env',
            $backup->legacyEnv => $backup->set.'/'.BackupSet::LEGACY_ENV,
            default => null,
        };
        $values = $file === null ? [] : LegacyEnv::read((string) @file_get_contents($file));

        return AppKeys::of(array_values(array_filter([
            $values['APP_KEY'] ?? '',
            ...explode(',', $values['APP_PREVIOUS_KEYS'] ?? ''),
        ], static fn (string $key): bool => trim($key) !== '')));
    }

    /** The oldest version the installed release upgrades from (its stored manifest), null when unknown. */
    private function upgradesFrom(?string $version): ?string
    {
        $json = $version === null ? null : $this->directory->read(ReleaseSource::RELEASES_DIR.'/'.$version.'/'.ManifestVerifier::MANIFEST); // U18: the file install.sh stores
        if ($json === null) {
            return null;
        }

        try {
            return (string) ReleaseManifest::fromJson($json, ManifestSchema::bundled())->upgradesFrom;
        } catch (Throwable) {
            return null;
        }
    }
}
