<?php

declare(strict_types=1);

namespace Tests\Unit\Forms;

use App\Domain\Forms\Import\Model\AcroField;
use App\Domain\Forms\Import\Model\AcroFormDocument;
use App\Domain\Forms\Import\PdfDefinitionBuilder;
use App\Domain\Forms\Import\PdfImportResult;
use App\Domain\Forms\Import\Qpdf\QpdfDocumentParser;
use FormSchema\Json\Json;
use stdClass;

/** Shared helpers for the PDF import tests: fixtures (tests/Fixtures/pdf) and definition lookups. */
final class PdfImportFixtures
{
    public static function path(string $file): string
    {
        return dirname(__DIR__, 2).'/Fixtures/pdf/'.$file;
    }

    /** The AcroForm of a fixture, parsed from its stored qpdf JSON dump (no qpdf needed). */
    public static function document(string $name): AcroFormDocument
    {
        $dump = json_decode((string) file_get_contents(self::path($name.'.qpdf.json')), true, 512, JSON_THROW_ON_ERROR);

        return (new QpdfDocumentParser)->parse($dump['structure'], array_map(
            static fn (string $data): string => (string) base64_decode($data, true),
            $dump['streams'],
        ));
    }

    public static function build(string $name, string $title = 'Imported form'): PdfImportResult
    {
        return (new PdfDefinitionBuilder)->build(self::document($name), $title, 'de-DE');
    }

    /** @return list<stdClass> every element, depth first */
    public static function elements(stdClass $definition): array
    {
        $all = [];
        $walk = function (array $elements) use (&$walk, &$all): void {
            foreach ($elements as $element) {
                $all[] = $element;
                $walk(is_array($element->elements ?? null) ? $element->elements : []);
            }
        };
        foreach ($definition->pages as $page) {
            $walk($page->elements);
        }

        return $all;
    }

    public static function element(stdClass $definition, string $key): stdClass
    {
        foreach (self::elements($definition) as $element) {
            if ($element->key === $key) {
                return $element;
            }
        }

        throw new \RuntimeException("No element with key {$key}");
    }

    public static function json(mixed $value): string
    {
        return Json::canonical(Json::decode(Json::encode($value)));
    }

    /** A synthetic text field at a given row position (for row detection and label tests). */
    public static function field(string $name, float $x = 50, float $y = 700, string $type = 'Tx', int $flags = 0, int $page = 1, ?string $tooltip = null): AcroField
    {
        return new AcroField($name, $type, $flags, 4, $page, [$x, $y, $x + 80, $y + 20], $tooltip);
    }
}
