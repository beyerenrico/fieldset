<?php

declare(strict_types=1);

namespace App\Domain\Reports\Exports\Writers;

/**
 * A number or money value as the exact decimal string of the submission ("1234.50"). XLSX writes it as a
 * text cell (openspout numeric cells need floats); CSV writes it with the locale's decimal separator.
 */
final readonly class DecimalCell
{
    public function __construct(public string $value) {}
}
