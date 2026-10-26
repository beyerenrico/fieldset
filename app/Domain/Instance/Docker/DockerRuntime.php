<?php

declare(strict_types=1);

namespace App\Domain\Instance\Docker;

/**
 * How the Docker engine maps container users to host files (KTD2, §6.3, ADR 0070). The shim and install.sh
 * detect it on the host (`docker info`, host OS) and pass it as FIELDSET_DOCKER_RUNTIME.
 *
 * - rootful: a native Linux daemon; container UIDs are host UIDs. The ops container runs as the host user with
 *   the socket's group added, and files in the instance directory are owned by the host UID.
 * - rootless: a user-namespaced daemon; container root is the host user.
 * - desktop: a VM-based engine (Docker Desktop, Colima, OrbStack, any non-Linux host); its file sharing maps
 *   files written by container root to the host user.
 *
 * On rootless and desktop the ops container runs as container root, and the in-container owner UID is 0.
 *
 * Since 2.1.1 (U3, KTD2, KTD3) these are only the fallback: the shim measures the owner in a container (0: root maps to
 * the host user; the host UID: IDs pass through, as on WSL 2 with Docker Desktop, whose runtime reads `desktop`) and saves
 * it with the runtime it was measured under; the runtime then decides when to measure again and which socket to mount.
 */
enum DockerRuntime: string
{
    case Rootful = 'rootful';
    case Rootless = 'rootless';
    case Desktop = 'desktop';

    /** The UID that owns instance files inside containers by the product-name rules, when no measurement is known. */
    public function containerOwnerUid(int $hostUid): int
    {
        return $this === self::Rootful ? $hostUid : 0;
    }
}
