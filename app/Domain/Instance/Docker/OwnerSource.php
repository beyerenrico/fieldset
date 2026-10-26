<?php

declare(strict_types=1);

namespace App\Domain\Instance\Docker;

/**
 * Where the launcher took the in-container owner (FIELDSET_CONTAINER_UID) of this run from (2.1.1 U3, KTD2, KTD3). The
 * shim passes it as FIELDSET_OWNER_SOURCE; launchers before 2.1.1 do not (null: the product-name rules of DockerRuntime).
 *
 * - measured: a throwaway container of the app image measured it just now (install, doctor and repair-ownership always;
 *   other commands without a saved owner or after the runtime changed)
 * - saved: the owner state.json recorded (`owner.runtime` matches the runtime detected this run)
 * - rules: the measurement could not run; WSL 2 means pass-through (rootless excepted), elsewhere DockerRuntime decides
 */
enum OwnerSource: string
{
    case Measured = 'measured';
    case Saved = 'saved';
    case Rules = 'rules';
}
