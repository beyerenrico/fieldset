<?php

declare(strict_types=1);

namespace App\Domain\Instance\Runtime;

use RuntimeException;

/** runtime.json is missing, unreadable or breaks its grammar; the message is a short, log-safe reason. */
final class InvalidRuntimeState extends RuntimeException {}
