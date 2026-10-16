<?php

declare(strict_types=1);

namespace App\Domain\Forms\Templates;

use FormSchema\Json\Json;
use InvalidArgumentException;
use stdClass;

/** Bundled templates from spec/templates (index.json + one definition file per template). */
final readonly class TemplateCatalog
{
    public function __construct(private string $directory) {}

    public static function bundled(): self
    {
        return new self(base_path('spec/templates'));
    }

    /** @return list<array{key: string, version: string, category: string, title: string}> */
    public function all(string $locale = 'de'): array
    {
        $templates = [];
        foreach ($this->index()->templates as $entry) {
            $titles = (array) $entry->title;
            $templates[] = [
                'key' => (string) $entry->key,
                'version' => (string) $entry->version,
                'category' => (string) $entry->category,
                'title' => (string) ($titles[$locale] ?? $titles['en'] ?? $entry->key),
            ];
        }

        return $templates;
    }

    /** Definition with `source` lineage set (D12). */
    public function definition(string $key): stdClass
    {
        $index = $this->index();
        foreach ($index->templates as $entry) {
            if ($entry->key !== $key) {
                continue;
            }
            $definition = Json::decode((string) file_get_contents($this->directory.'/'.basename((string) $entry->file)));
            if (! $definition instanceof stdClass) {
                break;
            }
            $definition->source = (object) ['registry' => $index->registry, 'template' => $entry->key, 'version' => $entry->version];

            return $definition;
        }

        throw new InvalidArgumentException(__('forms.errors.template_not_found'));
    }

    private function index(): stdClass
    {
        $index = Json::decode((string) file_get_contents($this->directory.'/index.json'));

        return $index instanceof stdClass ? $index : (object) ['registry' => '', 'templates' => []];
    }
}
