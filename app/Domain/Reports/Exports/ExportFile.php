<?php

declare(strict_types=1);

namespace App\Domain\Reports\Exports;

/** A finished export file on the local file system, before it is stored on the export disk. */
final readonly class ExportFile
{
    public function __construct(
        public string $localPath,
        public string $extension,
        public int $rows,
        public int $included,
        public int $skipped = 0,
    ) {}
}
