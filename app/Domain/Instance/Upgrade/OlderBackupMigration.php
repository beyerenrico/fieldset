<?php

declare(strict_types=1);

namespace App\Domain\Instance\Upgrade;

use App\Domain\Instance\Compose\ComposeRenderer;
use App\Domain\Instance\Docker\ComposeProject;
use App\Domain\Instance\Docker\DockerGateway;
use App\Domain\Instance\InstanceException;
use App\Domain\Instance\Lifecycle\OneOffResult;
use App\Domain\Instance\Output\ExitCode;

/**
 * The upgrade child for a restore of an older backup (KTD18, plan U17 step 6 and U18, ADR 0085 amendment, ADR 0086):
 * the installed release's `instance:upgrade-migrate` on the scratch database the backup was restored into, in a
 * socket-less one-off of the app service (DB_DATABASE by name, libpq's session name and lock_timeout like the
 * upgrade's child), before the finish step's carry, which needs this release's schema. Migrations from the backup's
 * version to the installed one and its version steps run; no foreign session may use the scratch database.
 */
final readonly class OlderBackupMigration
{
    public function __construct(private DockerGateway $docker) {}

    /**
     * @return array{migrated: list<string>, steps: list<string>}
     *
     * @throws InstanceException exit 5 `upgrade_child_failed` (the restore goes back)
     */
    public function run(ComposeProject $project, string $database, ?string $from, string $id): array
    {
        $command = ['php', 'artisan', 'instance:upgrade-migrate', '--application-name='.ChildMigration::applicationName($id), ...($from === null ? [] : ['--from='.$from]), '--json'];
        $outcome = OneOffResult::fromOutcome($this->docker->composeRun($project, ComposeRenderer::ONE_OFF_SERVICE, $command, ['DB_DATABASE' => $database, ...ChildMigration::libpq($id)], timeout: ChildMigration::TIMEOUT));
        if (! $outcome->ok) {
            throw new InstanceException('Migrating the restored database to this release failed: '.$outcome->errorMessage(), ExitCode::RolledBack, 'upgrade_child_failed',
                'Read the output; the live database was not touched. `fsa install --from-backup` into a new directory migrates it too.', true, ['code' => $outcome->errorCode(), 'output' => $outcome->output]);
        }

        return ['migrated' => UpgradeJournal::list($outcome->data['migrated'] ?? null), 'steps' => UpgradeJournal::list($outcome->data['steps'] ?? null)];
    }
}
