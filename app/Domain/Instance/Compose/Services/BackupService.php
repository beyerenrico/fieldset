<?php

declare(strict_types=1);

namespace App\Domain\Instance\Compose\Services;

use App\Domain\Instance\Backup\BackupConfig;
use App\Domain\Instance\Compose\ImageRole;
use App\Domain\Instance\Compose\RenderContext;
use App\Domain\Instance\Compose\ServiceDefinition;
use App\Domain\Instance\Compose\ServiceEnvironments;
use App\Domain\Instance\Compose\ServiceSpec;
use App\Domain\Instance\State\InstanceDirectory;

/**
 * The backup service (E13, KTD15, U16; ADR 0059, ADR 0084): the app image as `fsa backup-daemon`, always rendered.
 *
 * - Container root with every capability dropped but DAC_READ_SEARCH and DAC_OVERRIDE (the private disk's 0700
 *   directories belong to www-data), CHOWN and FOWNER (its output belongs to the in-container owner), no new
 *   privileges, a read-only root file system, and never the Docker socket.
 * - Mounts: the storage volume read-only; of the instance directory only the marker (read-only), `locks/` (it takes
 *   the instance lock per run) and `backups/`. Everything else it needs is in its env file (ServiceEnvironments::backup).
 * - The default network only, to reach Postgres as the backup role; it depends on a healthy Postgres.
 * - SIGTERM stops it between runs; a run cut off by the grace period leaves a `.partial` directory, removed by the
 *   next run, and a lock fsa takes over once the container is stopped (InstanceLock::isStale).
 */
final readonly class BackupService implements ServiceDefinition
{
    public const string NAME = 'backup';

    public const array CAPABILITIES = ['CHOWN', 'DAC_OVERRIDE', 'DAC_READ_SEARCH', 'FOWNER'];

    public const int STOP_GRACE_SECONDS = 30;

    public function specs(RenderContext $context): array
    {
        $root = BackupConfig::INSTANCE_ROOT;

        return [new ServiceSpec(self::NAME, [
            'image' => $context->image(ImageRole::App),
            'command' => ['fsa', 'backup-daemon'],
            'user' => '0:0',
            'cap_drop' => ['ALL'],
            'cap_add' => self::CAPABILITIES,
            'security_opt' => ['no-new-privileges:true'],
            'read_only' => true,
            'tmpfs' => ['/tmp'],
            'volumes' => [
                ['type' => 'volume', 'source' => AppRoles::STORAGE_VOLUME, 'target' => AppRoles::STORAGE_PATH, 'read_only' => true],
                $this->bind(InstanceDirectory::MARKER, $root.'/'.InstanceDirectory::MARKER, true),
                $this->bind(InstanceDirectory::LOCKS_DIR, $root.'/'.InstanceDirectory::LOCKS_DIR, false),
                $this->bind(InstanceDirectory::BACKUPS_DIR, $root.'/'.InstanceDirectory::BACKUPS_DIR, false),
            ],
            'networks' => ['default'],
            'depends_on' => [PostgresService::NAME => ['condition' => 'service_healthy']],
            'stop_grace_period' => self::STOP_GRACE_SECONDS.'s',
            'healthcheck' => ['disable' => true],
        ], new ServiceEnvironments($context)->backup(), [AppRoles::STORAGE_VOLUME => []])];
    }

    /** @return array<string, mixed> a bind mount of an instance-directory path that must exist already */
    private function bind(string $source, string $target, bool $readOnly): array
    {
        return ['type' => 'bind', 'source' => './'.$source, 'target' => $target, 'read_only' => $readOnly, 'bind' => ['create_host_path' => false]];
    }
}
