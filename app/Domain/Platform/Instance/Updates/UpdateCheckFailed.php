<?php

declare(strict_types=1);

namespace App\Domain\Platform\Instance\Updates;

use RuntimeException;

/** Why one update check failed, as a code the instance page translates: `unreachable`, `http_<status>`, `invalid_manifest`. */
final class UpdateCheckFailed extends RuntimeException {}
