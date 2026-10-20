<?php

declare(strict_types=1);

namespace App\Domain\Platform\Instance\Restore;

use App\Domain\Platform\Instance\BackupRole;
use Illuminate\Database\Connection;
use SensitiveParameter;

/**
 * Rights a restored database needs again (plan U17, ADR 0084 "left for U17", ADR 0085). Backups are dumped with
 * `--no-privileges`, so a restored database has the default ACLs: PUBLIC may EXECUTE every function, the SECURITY
 * DEFINER audit functions included, and the backup service's role (fsa_backup) with it could seal or prune audit
 * entries. The audit hardening's `REVOKE ALL … FROM PUBLIC` is applied again, and the backup role is set up again for
 * this database (BackupRole: CONNECT, read all data, insert into backup_runs).
 */
final readonly class RestoreGrants
{
    /** The audit functions whose EXECUTE only their owner keeps (migration harden_activity_log, ADR 0038). */
    public const array AUDIT_FUNCTIONS = [
        'audit_seal(bigint, bigint, character, character)',
        'audit_prune(timestamp without time zone)',
        'audit_prunable(timestamp without time zone)',
    ];

    public function __construct(private Connection $target, private BackupRole $role) {}

    /** @return array{revoked_public: list<string>, backup_role: array{role: string, created: bool, granted: list<string>}} */
    public function apply(#[SensitiveParameter] string $backupPassword, string $backupRole = BackupRole::NAME): array
    {
        $revoked = [];
        foreach (self::AUDIT_FUNCTIONS as $function) {
            if ($this->target->selectOne('SELECT to_regprocedure(?) AS oid', [$function])?->oid === null) {
                continue;
            }
            $this->target->statement("REVOKE ALL ON FUNCTION {$function} FROM PUBLIC");
            $revoked[] = $function;
        }

        return ['revoked_public' => $revoked, 'backup_role' => $this->role->ensure($backupPassword, $backupRole)];
    }
}
