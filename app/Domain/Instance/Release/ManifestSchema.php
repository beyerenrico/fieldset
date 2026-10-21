<?php

declare(strict_types=1);

namespace App\Domain\Instance\Release;

use Fieldset\Json\Json;
use Fieldset\Validation\MetaSchemaValidator;
use JsonException;

/** Validates a release manifest against `distribution/manifest.schema.json` (JSON Schema 2020-12, opis). */
final readonly class ManifestSchema
{
    public function __construct(private string $path) {}

    public static function bundled(): self
    {
        return new self(base_path('distribution/manifest.schema.json'));
    }

    /**
     * @return list<string> `<JSON pointer>: <message>`, empty when the manifest is valid
     */
    public function errors(string $json): array
    {
        try {
            $tree = Json::decode($json);
        } catch (JsonException) {
            return ['/: the manifest is not valid JSON'];
        }

        $result = new MetaSchemaValidator($this->path)->validate($tree);

        return array_map(
            static fn (array $error): string => ($error['path'] === '' ? '/' : $error['path']).': '.$error['message'],
            $result->errors,
        );
    }

    public function assertValid(string $json): void
    {
        $errors = $this->errors($json);
        if ($errors !== []) {
            throw new ReleaseException("The release manifest does not match its schema:\n- ".implode("\n- ", $errors));
        }
    }
}
