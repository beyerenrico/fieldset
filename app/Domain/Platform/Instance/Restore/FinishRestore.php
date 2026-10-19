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
 *    skips both: writers were stopped since its safety backup);
 * 3. sessions, remember tokens, password-reset tokens and sign-in links cleared;
 * 4. the audit functions' grants and the backup role (RestoreGrants);
 * 5. a decrypt sample with the instance's APP keys.
 */
final readonly class FinishRestore
{
    public const string SOURCE_CONNECTION = 'fsa_restore_source';

    public function __construct(private RestoredEpoch $epoch, private Encrypter $encrypter) {}

    /** @return array<string, mixed> */
    public function run(FinishRequest $request, #[SensitiveParameter] string $backupPassword): array
    {
        $target = DB::connection();
        if (! $target instanceof Connection) {
            throw new InvalidArgumentException('The restored database needs a database connection.');
        }
        if ($request->source !== null && $request->source === $target->getDatabaseName()) {
            throw new InvalidArgumentException('--source must name the pre-restore database, not the restored one (DB_DATABASE).');
        }
        $data = $this->epoch->begin($request->reason, $request->epochSeed, $request->previousHeads, $request->previousEpoch, $request->backup, $request->fromBackup, $request->actorUid);
        [$data['carried'], $data['sequences']] = $request->source !== null && $request->carry ? $this->carry($request->source, $target) : [null, null];
        $data['cleared'] = new StaleCredentials($target)->clear();
        $data['grants'] = new RestoreGrants($target, new BackupRole($target))->apply($backupPassword, $request->backupRole);
        $data['decrypt'] = new DecryptSample($target, $this->encrypter)->run();

        return $data;
    }

    /** @return array{0: array<string, mixed>, 1: array{sequences: int, number_sequences: int}} */
    private function carry(string $source, Connection $target): array
    {
        $previous = RestoreConnections::to($source, self::SOURCE_CONNECTION);
        $previous->statement('SET SESSION CHARACTERISTICS AS TRANSACTION READ ONLY'); // the carry only reads the pre-restore database

        try {
            return [new SecurityStateCarrier($previous, $target)->carry(), new SequenceAdvancer($previous, $target)->advance()];
        } finally {
            RestoreConnections::close(self::SOURCE_CONNECTION);
        }
    }
}
