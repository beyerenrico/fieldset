<?php

declare(strict_types=1);

namespace App\Domain\Instance\Install;

use App\Domain\Instance\Docker\ComposeProject;
use App\Domain\Instance\Docker\DockerGateway;
use App\Domain\Instance\Docker\DockerResource;
use App\Domain\Instance\Restore\PreparedBackup;
use App\Domain\Instance\Restore\RestoreDatabases;
use App\Domain\Instance\Restore\RestoreStorage;
use App\Domain\Instance\State\InstanceSettings;
use App\Domain\Instance\State\InstanceState;

/**
 * The data of `fsa install --from-backup` (plan U17, KTD18, ADR 0085), with the restore's own steps: the dump goes into a
 * scratch database `<db>_restore_<id>` (a role owning nothing else for an unauthenticated archive), which then takes the
 * live name from the empty database Postgres created at its first start; the storage archive fills the fresh volume the
 * state names. Both steps start over cleanly when a resumed install repeats them: what an interrupted attempt left of
 * its own names goes first, and a swap that already happened is not repeated.
 */
final readonly class FromBackupData
{
    public function __construct(private RestoreDatabases $databases, private RestoreStorage $storage, private DockerGateway $docker) {}

    /** @return array{database: string, restricted: bool} */
    public function database(InstanceState $state, ComposeProject $project, PreparedBackup $backup, string $id): array
    {
        $live = InstanceSettings::of($state)->dbDatabase();
        $base = substr($live, 0, 63 - strlen('_pre_restore_') - strlen($id));
        [$scratch, $aside, $role] = [$base.'_restore_'.$id, $base.'_pre_restore_'.$id, 'fsa_restore_'.$id];
        $databases = $this->databases->list($project, $base);
        if (in_array($aside, $databases, true)) { // swapped in an earlier attempt: only the empty database is left to drop
            $this->databases->drop($project, $aside);

            return ['database' => $live, 'restricted' => ! $backup->authenticated()];
        }
        $this->databases->drop($project, $scratch, $role);
        $created = $this->databases->create($project, $scratch, $backup->authenticated() ? null : $role);
        $as = $created['role'] === null ? null : ['username' => $created['role'], 'password' => (string) $created['password']];
        $this->databases->restore($state, $project, $backup, $scratch, $as);
        if ($created['role'] !== null) {
            $this->databases->adopt($project, $scratch, $created['role']);
        }
        $this->databases->swap($project, $scratch, $live, $aside);
        $this->databases->drop($project, $aside);

        return ['database' => $live, 'restricted' => $created['role'] !== null];
    }

    /** @return array{volume: string, files: int} */
    public function storage(InstanceState $state, PreparedBackup $backup): array
    {
        $volume = RestoreStorage::current($state);
        if (array_any($this->docker->volumes([]), static fn (DockerResource $resource): bool => $resource->name === $volume)) {
            $this->storage->remove($volume); // an interrupted attempt's, never an instance's own: the name is this install's
        }
        $this->storage->fill($state, $volume, $backup->storage['archive'] ?? null);

        return ['volume' => $volume, 'files' => $backup->storage['files'] ?? 0];
    }

    /**
     * `instance:restore-finish` without a source: the new epoch with `instance.restored`, stale credentials, grants and
     * the decrypt sample (no carry: nothing came before on this host).
     *
     * @return array<string, mixed>
     */
    public function finish(InstanceState $state, ComposeProject $project, PreparedBackup $backup, int $actorUid): array
    {
        $epoch = is_int($backup->manifest['epoch'] ?? null) ? $backup->manifest['epoch'] : 0;

        return $this->databases->finish($project, InstanceSettings::of($state)->dbDatabase(), null, $backup->name, $epoch, $actorUid,
            ['previous_heads' => (object) [], 'previous_epoch' => null, 'from_backup' => true]);
    }
}
