<?php

declare(strict_types=1);

namespace App\Domain\Reports\Exports\Writers;

/**
 * Streams an export to a local file: one main sheet of submissions and one sheet per repeater. Cells are
 * string|int|bool|DateCell|null; number and money values arrive as exact decimal strings.
 */
interface ExportWriter
{
    /** @param  array<string, ExportSheet>  $repeaters  repeater key => sheet */
    public function open(string $directory, ExportSheet $main, array $repeaters): void;

    /** @param  list<string|int|bool|DateCell|null>  $cells */
    public function submission(array $cells): void;

    /** @param  list<string|int|bool|DateCell|null>  $cells */
    public function repeaterRow(string $repeaterKey, array $cells): void;

    /** Closes the writer; returns the local path and extension of the finished file. */
    public function close(): WrittenFile;
}
