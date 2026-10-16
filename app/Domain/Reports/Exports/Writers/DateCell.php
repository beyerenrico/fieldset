<?php

declare(strict_types=1);

namespace App\Domain\Reports\Exports\Writers;

use DateTimeImmutable;

/** A date (or date and time, already in the export's time zone) for a spreadsheet cell. */
final readonly class DateCell
{
    public function __construct(public DateTimeImmutable $value, public bool $withTime) {}

    public function text(): string
    {
        return $this->value->format($this->withTime ? 'Y-m-d H:i' : 'Y-m-d');
    }
}
