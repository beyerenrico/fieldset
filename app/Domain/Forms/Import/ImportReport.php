<?php

declare(strict_types=1);

namespace App\Domain\Forms\Import;

use App\Domain\Forms\Import\Mapping\UnmappedField;

/**
 * The import report stored on the form (`forms.import_report`) until someone dismisses it: every
 * field that was not imported (page, name, reason), warnings and counts (§8.13).
 */
final readonly class ImportReport
{
    /**
     * @param  list<UnmappedField>  $unmapped
     * @param  list<array{code: string, field?: string, page?: int, params?: array<string, int|string>}>  $warnings
     * @param  array<string, int>  $stats
     */
    public function __construct(
        public array $unmapped,
        public array $warnings,
        public array $stats,
        public ?string $file = null,
    ) {}

    public function withFile(string $file): self
    {
        return new self($this->unmapped, $this->warnings, $this->stats, $file);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'source' => 'acroform',
            'file' => $this->file,
            'unmapped' => array_map(static fn (UnmappedField $u): array => $u->toArray(), $this->unmapped),
            'warnings' => $this->warnings,
            'stats' => $this->stats,
        ];
    }
}
