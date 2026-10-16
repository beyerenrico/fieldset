<?php

declare(strict_types=1);

namespace App\Domain\Reports\Exports;

use App\Domain\Submissions\Models\Submission;

/**
 * One submission with its projected values pivoted by key: top-level values, repeater rows
 * (repeater key => row number "1", nested "1.2" => child key => value_text) and the row count of
 * each top-level repeater.
 */
final readonly class ExportRecord
{
    /**
     * @param  array<string, string|null>  $values
     * @param  array<string, array<string, array<string, string|null>>>  $rows
     * @param  array<string, int>  $rowCounts
     */
    public function __construct(
        public Submission $submission,
        public array $values,
        public array $rows,
        public array $rowCounts,
    ) {}
}
