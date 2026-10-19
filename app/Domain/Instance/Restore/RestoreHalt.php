<?php

declare(strict_types=1);

namespace App\Domain\Instance\Restore;

use App\Domain\Instance\Compose\Services\AppRoles;
use App\Domain\Instance\Compose\Services\BackupService;
use App\Domain\Instance\Docker\ComposeProject;
use App\Domain\Instance\Docker\DockerGateway;
use App\Domain\Instance\InstanceException;
use App\Domain\Instance\Output\CommandResult;
use App\Domain\Instance\Output\ExitCode;
use Throwable;

/**
 * Exit 8 of a restore (plan U17, ADR 0085): every writer stops, the gate stays up, the journal stays open, and the
 * result names what is where and the one command that continues (third review L4, L5):
 *
 * - `abort`: going back before the swap failed; nothing was swapped, `fsa restore --abandon` starts the previous instance;
 * - `return`: returning to the previous instance failed; `--abandon` (or the same `fsa restore`) continues returning;
 * - `no_way_back`: the put-aside database or the previous volume is gone; `--abandon` continues with the restored data;
 * - `forward`: continuing with the restored data failed too.
 *
 * The safety backup cannot be restored in place while the journal is open (`fsa restore` of another backup exits 9), so
 * the last resort is always installing it into a new directory with `fsa install --from-backup`.
 */
final readonly class RestoreHalt
{
    /** Everything that writes to the database or the storage volume. */
    public const array WRITERS = [AppRoles::APP, AppRoles::QUEUE, AppRoles::SCHEDULER, BackupService::NAME];

    public function __construct(private DockerGateway $docker) {}

    public function stop(ComposeProject $project, RestoreNames $names, Throwable $cause, Throwable $failure, ?string $safetyBackup, string $kind): CommandResult
    {
        try {
            $this->docker->composeStop($project, self::WRITERS);
        } catch (Throwable) {
            // already stopped or Docker unreachable: the result says what is where either way
        }
        $kind = $kind === 'return' && $failure instanceof InstanceException && in_array($failure->errorCode, ['previous_database_missing', 'previous_volume_missing'], true) ? 'no_way_back' : $kind;
        $summary = match ($kind) {
            'abort' => 'The restore failed before the swap ('.$cause->getMessage().') and starting the previous instance again failed too ('.$failure->getMessage().'); the instance is stopped behind its gate.',
            'forward' => 'There is no way back, and the restored instance did not start either ('.$failure->getMessage().'); the instance is stopped behind its gate.',
            default => 'The restore failed ('.$cause->getMessage().') and returning to the previous instance failed too ('.$failure->getMessage().'); the instance is stopped behind its gate.',
        };

        return CommandResult::failed('restore', ExitCode::RollbackFailed, 'restore_rollback_failed', $summary, $this->hint($names, $kind, $safetyBackup), false,
            ['names' => $names->toArray(), 'safety_backup' => $safetyBackup, 'next' => $kind], changed: true);
    }

    private function hint(RestoreNames $names, string $kind, ?string $safetyBackup): string
    {
        $where = "The previous database is {$names->aside} (or {$names->live}), the restored one {$names->live} (or {$names->scratch}); the previous storage volume is {$names->currentVolume}, the restored one {$names->freshVolume}.";
        $last = ' If that fails too, install the safety backup'.($safetyBackup === null ? '' : " {$safetyBackup}").' into a new directory with `fsa install --from-backup`.';

        return match ($kind) {
            'abort' => "Nothing was swapped: {$names->live} and {$names->currentVolume} are unchanged. Read `fsa logs app`, then run `fsa restore --abandon` to start the previous instance again.",
            'no_way_back' => "{$where} There is no way back: run `fsa restore --abandon` to continue with the restored database and volume.".$last,
            'forward' => "{$where} Read `fsa logs app`, then run `fsa restore --abandon` again.".$last,
            default => "{$where} Run `fsa restore --abandon` (or the same `fsa restore` again) to continue returning to the previous instance.".$last,
        };
    }
}
