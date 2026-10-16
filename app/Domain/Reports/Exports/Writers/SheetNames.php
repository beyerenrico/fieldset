<?php

declare(strict_types=1);

namespace App\Domain\Reports\Exports\Writers;

/** Unique spreadsheet sheet names and file names derived from labels (XLSX: ≤ 31 characters, no []:*?/\). */
final class SheetNames
{
    /** @var array<string, true> */
    private array $used = [];

    public function sheet(string $label): string
    {
        $base = trim((string) preg_replace('/[\[\]:*?\/\\\\\x00-\x1F]+/u', ' ', $label), " '");
        $base = mb_substr($base === '' ? 'Sheet' : $base, 0, 31);
        $name = $base;
        for ($i = 2; isset($this->used[mb_strtolower($name)]); $i++) {
            $name = mb_substr($base, 0, 31 - strlen((string) $i) - 1).' '.$i;
        }
        $this->used[mb_strtolower($name)] = true;

        return $name;
    }

    /** A safe file name stem (CSV files inside the ZIP). */
    public function file(string $label): string
    {
        $stem = trim((string) preg_replace('/[^A-Za-z0-9._-]+/', '-', $label), '-.');

        return $this->sheet($stem === '' ? 'rows' : $stem);
    }
}
