<?php

declare(strict_types=1);

namespace App\Domain\Instance\Restore;

use RuntimeException;

/** A backup archive a restore refuses to unpack (plan U17, ADR 0085): a hostile or damaged member, never a guess. */
final class UnsafeArchive extends RuntimeException {}
