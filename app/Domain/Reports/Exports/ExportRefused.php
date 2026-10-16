<?php

declare(strict_types=1);

namespace App\Domain\Reports\Exports;

use RuntimeException;

/** An export request that StartExport refuses (too many rows, too many open exports, throttled); the message is user-facing. */
final class ExportRefused extends RuntimeException {}
