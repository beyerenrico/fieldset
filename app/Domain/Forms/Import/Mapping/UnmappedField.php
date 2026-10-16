<?php

declare(strict_types=1);

namespace App\Domain\Forms\Import\Mapping;

/** A PDF field that was not imported, with a stable reason code (`forms.import.reasons.*`). */
final readonly class UnmappedField
{
    public const array REASONS = [
        'push_button', 'hidden', 'custom_calculation', 'unsupported_calculation', 'calculation_reference',
        'file_select', 'no_options', 'unsupported_type',
    ];

    public function __construct(public string $field, public int $page, public string $reason) {}

    /** @return array{field: string, page: int, reason: string} */
    public function toArray(): array
    {
        return ['field' => $this->field, 'page' => $this->page, 'reason' => $this->reason];
    }
}
