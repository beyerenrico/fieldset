<?php

declare(strict_types=1);

namespace App\Domain\Forms\Import\Mapping;

use App\Domain\Forms\Import\Model\AcroField;

/**
 * A field the importer can represent: element type, type-specific properties, the built-in
 * calculation (for `calculated`), the mapping confidence and warnings for the report.
 */
final readonly class MappedField
{
    /**
     * @param  array<string, mixed>  $properties  element properties besides id/type/key/label
     * @param  array{function: string, fields: list<string>}|null  $calculation
     * @param  list<array{code: string, params?: array<string, int|string>}>  $warnings
     */
    public function __construct(
        public AcroField $field,
        public string $type,
        public array $properties = [],
        public float $confidence = 1.0,
        public ?array $calculation = null,
        public array $warnings = [],
    ) {}

    public function isCalculated(): bool
    {
        return $this->type === 'calculated';
    }
}
