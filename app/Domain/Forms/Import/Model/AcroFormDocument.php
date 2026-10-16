<?php

declare(strict_types=1);

namespace App\Domain\Forms\Import\Model;

/** What the importer needs from a fillable PDF: pages, fields and visible page text. */
final readonly class AcroFormDocument
{
    /**
     * @param  list<AcroField>  $fields  in document order
     * @param  list<TextRun>  $texts
     * @param  list<string>  $warnings  reader warning codes (e.g. `damaged_file`)
     */
    public function __construct(
        public int $pageCount,
        public array $fields,
        public array $texts = [],
        public ?string $title = null,
        public array $warnings = [],
    ) {}

    /** @return list<TextRun> */
    public function textsOnPage(int $page): array
    {
        return array_values(array_filter($this->texts, static fn (TextRun $run): bool => $run->page === $page));
    }
}
