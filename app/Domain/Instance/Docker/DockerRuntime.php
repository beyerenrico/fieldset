<?php

declare(strict_types=1);

namespace App\Domain\Instance\Docker;

/**
 * How the Docker engine maps container users to host files (KTD2, §6.3, ADR 0070). The shim and install.sh
 * detect it on the host (`docker info`, host OS) and pass it as FSA_DOCKER_RUNTIME.
 *
 * - rootful: a native Linux daemon; container UIDs are host UIDs. The ops container runs as the host user with
 *   the socket's group added, and files in the instance directory are owned by the host UID.
 * - rootless: a user-namespaced daemon; container root is the host user.
 * - desktop: a VM-based engine (Docker Desktop, Colima, OrbStack, any non-Linux host); its file sharing maps
 *   files written by container root to the host user.
 *
 * On rootless and desktop the ops container runs as container root, and the in-container owner UID is 0.
 */
enum DockerRuntime: string
{
    case Rootful = 'rootful';
    case Rootless = 'rootless';
    case Desktop = 'desktop';

    /** The UID that owns instance files inside containers (backup chown, restore, doctor ownership check). */
    public function containerOwnerUid(int $hostUid): int
    {
        return $this === self::Rootful ? $hostUid : 0;
    }
}
