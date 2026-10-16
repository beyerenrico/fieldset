<?php

declare(strict_types=1);

namespace App\Domain\Workflows\Engine;

use RuntimeException;

/** A permanent step failure (bad configuration, refused address, missing data): no retry, the run fails. */
final class StepFailedException extends RuntimeException {}
