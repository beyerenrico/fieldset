<?php

declare(strict_types=1);

namespace Fieldset\Validation;

use Fieldset\Json\JsonPointer;
use Fieldset\Support\SpecPaths;
use Opis\JsonSchema\CompliantValidator;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Errors\ValidationError;
use Opis\JsonSchema\Validator;
use RuntimeException;
use stdClass;

/**
 * Validates a definition against meta-schema.json (JSON Schema 2020-12) with opis/json-schema.
 */
final class MetaSchemaValidator
{
    private const DEFAULT_ID = 'https://localhost/spec/form-definition/1.0/meta-schema.json';

    private readonly Validator $validator;

    private readonly string $schemaId;

    public function __construct(?string $schemaPath = null, int $maxErrors = 50)
    {
        $schema = SpecPaths::readJson($schemaPath ?? SpecPaths::metaSchema());

        if (! $schema instanceof stdClass) {
            throw new RuntimeException('The meta-schema must be a JSON object.');
        }

        $id = $schema->{'$id'} ?? self::DEFAULT_ID;
        $this->schemaId = is_string($id) ? $id : self::DEFAULT_ID;
        $this->validator = new CompliantValidator(null, max(1, $maxErrors), false);
        $this->validator->resolver()?->registerRaw($schema, $this->schemaId);
    }

    /**
     * @param  mixed  $definition  JSON tree as produced by Json::decode()
     */
    public function validate(mixed $definition): MetaSchemaResult
    {
        $error = $this->validator->validate($definition, $this->schemaId)->error();

        if ($error === null) {
            return new MetaSchemaResult(true);
        }

        $formatter = new ErrorFormatter;
        $leaves = self::leaves($error);
        $paths = array_map(self::pointer(...), $leaves);
        $errors = [];

        foreach ($leaves as $index => $leaf) {
            if (self::isCascade($leaf, $paths[$index], $paths)) {
                continue;
            }

            $entry = ['path' => $paths[$index], 'message' => $formatter->formatErrorMessage($leaf)];
            $errors[$entry['path'].'|'.$entry['message']] = $entry;
        }

        return new MetaSchemaResult(false, array_values($errors));
    }

    /**
     * When a nested property fails, opis also reports the enclosing object's properties as
     * unevaluated. Those follow-up errors are dropped in favour of the nested cause.
     *
     * @param  list<string>  $paths
     */
    private static function isCascade(ValidationError $error, string $path, array $paths): bool
    {
        if (! in_array($error->keyword(), ['additionalProperties', 'unevaluatedProperties'], true)) {
            return false;
        }

        foreach ($paths as $other) {
            if (str_starts_with($other, $path.'/')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<ValidationError>
     */
    private static function leaves(ValidationError $error): array
    {
        $children = $error->subErrors();

        if ($children === []) {
            return [$error];
        }

        $leaves = [];
        foreach ($children as $child) {
            if ($child instanceof ValidationError) {
                array_push($leaves, ...self::leaves($child));
            }
        }

        return $leaves;
    }

    private static function pointer(ValidationError $error): string
    {
        return JsonPointer::fromSegments(array_values($error->data()->fullPath()));
    }
}
