<?php

declare(strict_types=1);

namespace App\Domain\Instance\Upgrade;

use App\Domain\Instance\Docker\ComposeProject;
use App\Domain\Instance\Docker\DockerGateway;
use App\Domain\Instance\InstanceException;
use App\Domain\Instance\Output\CommandResult;
use App\Domain\Instance\Output\ExitCode;
use App\Domain\Instance\Restore\RestoreHalt;
use App\Domain\Instance\Restore\RestoreNames;
use App\Domain\Instance\State\InstanceDirectory;
use Throwable;

/**
 * Exit 8 of an upgrade (R55, plan U18, ADR 0086): the rollback itself failed. Every writer stops, the gate stays up, the
 * journal stays open, and the result carries what an operator needs: the safety backup's path, both digest sets, where
 * each database and volume is, and the way on: `fieldset upgrade --resume` repeats the rollback; the last resort is installing
 * the safety backup into a new directory with `install.sh --dir <new directory> --from-backup <path>`.
 */
final readonly class UpgradeHalt
{
    public function __construct(private InstanceDirectory $directory, private DockerGateway $docker) {}

    /**
     * @param  array<string, mixed>  $release  the journaled target (digests, previous digests)
     */
    public function stop(ComposeProject $project, RestoreNames $names, array $release, ?string $safetyBackup, Throwable $cause, Throwable $failure): CommandResult
    {
        try {
            $this->docker->composeStop($project, RestoreHalt::WRITERS);
        } catch (Throwable) {
            // already stopped or Docker unreachable: the result says what is where either way
        }
        $path = $safetyBackup === null ? null : $this->directory->root.'/'.InstanceDirectory::BACKUPS_DIR.'/'.$safetyBackup;
        $where = "The database the upgrade migrated is {$names->live} (or {$names->aside}), the restored one {$names->scratch} (or {$names->live}); the storage volume before the rollback is {$names->currentVolume}, the restored one {$names->freshVolume}.";

        return CommandResult::failed('upgrade', ExitCode::RollbackFailed, 'upgrade_rollback_failed',
            'The upgrade failed ('.$cause->getMessage().') and rolling it back failed too ('.$failure->getMessage().'); the instance is stopped behind its gate.',
            $where.' Run `fieldset upgrade --resume` to repeat the rollback. If that fails too, install the safety backup into a new directory with `install.sh --dir <new directory> --from-backup '.($path ?? '<backup>').'`.',
            false,
            [
                'safety_backup' => $path, 'names' => $names->toArray(), 'next' => 'fieldset upgrade --resume',
                'digests' => ['previous' => UpgradeJournal::strings($release['previous_digests'] ?? null), 'target' => UpgradeJournal::strings($release['digests'] ?? null)],
                'cause' => $cause instanceof InstanceException ? $cause->errorCode : 'upgrade_failed', 'failure' => $failure instanceof InstanceException ? $failure->errorCode : 'rollback_failed',
            ],
            changed: true,
        );
    }
}
