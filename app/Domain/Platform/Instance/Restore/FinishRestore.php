<?php

declare(strict_types=1);

namespace App\Domain\Platform\Instance\Restore;

use App\Domain\Platform\Instance\BackupRole;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use SensitiveParameter;

/**
 * Prepares a restored database for the swap (plan U17 steps 5, 7–9; KTD18, KTD26; ADR 0085), in the one-off whose
 * default connection is that database (`instance:restore-finish`):
 *
 * 1. a new audit epoch and `instance.restored` as its first entry (RestoredEpoch);
 * 2. with the pre-restore database: the R64 security-state carry and the sequence floors (U18's automatic rollback
 *    skips both: writers were stopped since its safety backup; a manual `fsa rollback` reads them from the newer
 *    release's SecurityExport, materialised by SecurityImport, because the older code cannot read the newer schema);
 * 3. sessions, remember tokens, password-reset tokens and sign-in links cleared;
 * 4. the audit functions' grants and the backup role (RestoreGrants);
 * 5. a decrypt sample with the instance's APP keys.
 *
 * All of it is one transaction on the restored database (review A9): a step that fails, the decrypt sample included,
 * leaves no epoch, no audit entry and no half carry behind, so the next run starts from the restored state again.
 */
final readonly class FinishRestore
{
    public const string SOURCE_CONNECTION = 'fieldset_restore_source';

    /** The carry's keys without a pre-restore database (`--from-backup`, U18's rollback). */
    private const array NO_CARRY = ['carried' => null, 'carry_mode' => null, 'carry_skipped' => [], 'cascade_skipped' => [], 'sequences' => null];

    public function __construct(private RestoredEpoch $epoch, private Encrypter $encrypter) {}

    /**
     * @return array<string, mixed>
     *
     * @throws RestoreRefused `decrypt_failed` when the APP keys open no sample of an encrypted column (nothing written)
     */
    public function run(FinishRequest $request, #[SensitiveParameter] string $backupPassword): array
    {
        $target = DB::connection();
        if (! $target instanceof Connection) {
            throw new InvalidArgumentException('The restored database needs a database connection.');
        }
        if ($request->source !== null && $request->source === $target->getDatabaseName()) {
            throw new InvalidArgumentException('--source must name the pre-restore database, not the restored one (DB_DATABASE).');
        }

        $import = $request->carryDocument !== null && $request->carry ? new SecurityImport($target) : null;
        try {
            $exported = $import?->source($request->carryDocument ?? []); // committed before the transaction: the carry reads it on its own connection

            return $target->transaction(fn (): array => $this->finish($request, $target, $backupPassword, $exported));
        } finally {
            $import?->drop();
        }
    }

    /** @return array<string, mixed> */
    private function finish(FinishRequest $request, Connection $target, #[SensitiveParameter] string $backupPassword, ?Connection $exported): array
    {
        $data = $this->epoch->begin($request->reason, $request->epochSeed, $request->previousHeads, $request->previousEpoch, $request->backup, $request->fromBackup, $request->actorUid, $request->journal);
        $data = [...$data, ...match (true) {
            ! $request->carry => self::NO_CARRY,
            $exported instanceof Connection => $this->carryFrom($exported, $target, $request),
            $request->source !== null => $this->carry($request->source, $target, $request),
            default => self::NO_CARRY,
        }];
        $data['cleared'] = new StaleCredentials($target)->clear();
        $data['grants'] = new RestoreGrants($target, new BackupRole($target))->apply($backupPassword, $request->backupRole);
        $data['decrypt'] = new DecryptSample($target, $this->encrypter)->run();
        if ($data['decrypt']['failed'] !== []) {
            throw new RestoreRefused('decrypt_failed', 'The instance\'s APP keys cannot decrypt '.implode(', ', $data['decrypt']['failed']).'.', 'Pass the APP_KEY the backup was made with (`--app-key-file`): it becomes a previous key.');
        }

        return $data;
    }

    /** @return array<string, mixed> carried, carry_mode, carry_skipped, cascade_skipped, sequences */
    private function carry(string $source, Connection $target, FinishRequest $request): array
    {
        try {
            return $this->carryFrom(RestoreConnections::to($source, self::SOURCE_CONNECTION), $target, $request);
        } finally {
            RestoreConnections::close(self::SOURCE_CONNECTION);
        }
    }

    /** @return array<string, mixed> the carry and the sequence floors from a read-only source */
    private function carryFrom(Connection $previous, Connection $target, FinishRequest $request): array
    {
        $previous->statement('SET SESSION CHARACTERISTICS AS TRANSACTION READ ONLY'); // the carry only reads its source

        return [...new SecurityStateCarrier($previous, $target, $request->backupCreatedAt)->carry(), 'sequences' => new SequenceAdvancer($previous, $target)->advance()];
    }
}
