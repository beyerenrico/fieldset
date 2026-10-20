<?php

declare(strict_types=1);

namespace App\Domain\Platform\Instance\Restore;

use Illuminate\Database\Connection;
use Illuminate\Database\Migrations\Migrator;
use Symfony\Component\Console\Output\NullOutput;

/**
 * The baseline of the schema audit (review S3, U18, ADR 0085 amendment): the reference at the restored database's own
 * migration set. When that set equals the reference's (an archive of this version), the reference itself; when it is
 * older, a database this image migrates to exactly that set, created like the restore's scratch database
 * (DatabaseAdmin::createReference) and dropped afterwards. Its name derives from the audited database, so a resumed
 * audit drops what an interrupted one left. A set naming migrations this image does not ship is refused outright.
 */
final readonly class AuditBaseline
{
    public const string PREFIX = 'fsa_restore_ref_';

    private const string CONNECTION = 'fsa_restore_audit_baseline';

    public function __construct(private Migrator $migrator) {}

    /** @return list<string> SchemaAudit::mismatches against the baseline, or the unknown migrations (at most 50) */
    public function mismatches(DatabaseAdmin $admin, string $database, Connection $restored, Connection $reference): array
    {
        $set = SchemaAudit::migrations($restored) ?? [];
        if ($set === (SchemaAudit::migrations($reference) ?? [])) {
            return SchemaAudit::mismatches($restored, $reference);
        }
        $files = $this->migrator->getMigrationFiles($this->paths());
        $unknown = array_values(array_diff($set, array_keys($files)));
        if ($unknown !== []) {
            return array_slice(array_map(static fn (string $name): string => "unknown migration {$name}", $unknown), 0, 50);
        }

        return $this->at($admin, self::name($database), array_values(array_intersect_key($files, array_flip($set))), static fn (Connection $baseline): array => SchemaAudit::mismatches($restored, $baseline));
    }

    /** The baseline database of an audited database: DROPPABLE, a valid name, the same on every attempt. */
    public static function name(string $database): string
    {
        return self::PREFIX.substr(hash('sha256', $database), 0, 16);
    }

    /**
     * @param  list<string>  $files  the migration files to run, in order
     * @param  callable(Connection): list<string>  $audit
     * @return list<string>
     */
    private function at(DatabaseAdmin $admin, string $name, array $files, callable $audit): array
    {
        $live = RestoreConnections::current();
        $admin->drop($name, null, $live);
        $admin->createReference($name);
        try {
            $baseline = RestoreConnections::to($name, self::CONNECTION);
            if ($files !== []) { // no migrations table in the archive: the baseline has none either
                $this->migrate($files);
            }

            return $audit($baseline);
        } finally {
            RestoreConnections::close(self::CONNECTION);
            $admin->drop($name, null, $live);
        }
    }

    /** @param  list<string>  $files */
    private function migrate(array $files): void
    {
        $this->migrator->setOutput(new NullOutput);
        $this->migrator->usingConnection(self::CONNECTION, function () use ($files): void {
            $this->migrator->getRepository()->createRepository();
            $this->migrator->requireFiles($files);
            $this->migrator->runPending($files);
        });
    }

    /** @return list<string> the image's migration directories: packages' own and database/migrations, as `migrate` runs them */
    private function paths(): array
    {
        return array_values([...$this->migrator->paths(), database_path('migrations')]);
    }
}
