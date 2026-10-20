<?php

declare(strict_types=1);

namespace App\Domain\Platform\Instance\Upgrade;

use Illuminate\Database\Connection;

/**
 * A version-specific upgrade step (R36, plan U18, ADR 0086): data work a release needs beyond its migrations, run by the
 * target's upgrade child after the migrations when the instance comes from a version before `since()`. Every step must be
 * idempotent: a resumed or repeated upgrade, and a restore of an older backup, may run it again.
 *
 * A step changes data only, never the schema: no table, column, index, function, trigger, view or policy. Schema belongs
 * in a migration, because the restore's schema audit compares an archive with a baseline built from migrations alone
 * (AuditBaseline, ADR 0085): an object a step created would be "unknown" there, and one it dropped "missing". It must
 * not use CONCURRENTLY either (UpgradeMigrationLintTest lints VersionSteps::STEPS).
 */
interface VersionStep
{
    /** The first release that needs it (an instance upgrading from an older version runs it). */
    public function since(): string;

    /** A short, stable name for the result and the logs. */
    public function name(): string;

    public function run(Connection $db): void;
}
