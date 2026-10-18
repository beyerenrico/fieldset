<?php

declare(strict_types=1);

namespace App\Domain\Instance\Release;

use RuntimeException;

/** A release input, registry answer or manifest that fails the release contract (plan U7, ADR 0067). */
final class ReleaseException extends RuntimeException {}
