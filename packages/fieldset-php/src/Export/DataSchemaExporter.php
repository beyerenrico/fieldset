<?php

declare(strict_types=1);

namespace Fieldset\Export;

use Fieldset\Definition\Definition;
use Fieldset\Definition\Element;
use Fieldset\Definition\Scope;
use stdClass;

/**
 * Exports a JSON Schema 2020-12 describing the submission data of a definition (spec §10).
 *
 * Types and structure only, not a validator. The schema describes non-empty values; empty values
 * of optional elements (null, "") are not modeled. Calculated values may be null.
 */
final class DataSchemaExporter
{
    public const DRAFT = 'https://json-schema.org/draft/2020-12/schema';

    public const DECIMAL_PATTERN = '^-?(0|[1-9][0-9]*)(\.[0-9]+)?$';

    public const PHONE_PATTERN = '^\+?[0-9 ()./-]{3,32}$';

    /** `HH:MM(:SS)` is not RFC 3339 full-time, so `format: time` would reject valid values. */
    public const TIME_PATTERN = '^([01][0-9]|2[0-3]):[0-5][0-9](:[0-5][0-9])?$';

    public function export(mixed $definition): stdClass
    {
        $parsed = Definition::fromJson($definition);
        $title = $parsed->raw->title ?? null;

        return self::object([
            '$schema' => self::DRAFT,
            ...(is_string($title) ? ['title' => $title] : []),
            ...self::scope($parsed, $parsed->rootScope()),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private static function scope(Definition $definition, Scope $scope): array
    {
        $properties = new stdClass;

        foreach ($scope->elements as $key => $element) {
            $schema = self::element($definition, $element);

            if ($schema !== null) {
                $properties->{$key} = self::annotate($element, $schema);
            }
        }

        return ['type' => 'object', 'properties' => $properties, 'additionalProperties' => false];
    }

    /**
     * Schema of one data element; null for types outside spec §3.2 (they are not exported).
     *
     * @return array<string, mixed>|null
     */
    private static function element(Definition $definition, Element $element): ?array
    {
        return match ($element->type) {
            'text', 'textarea' => ['type' => 'string'],
            'email' => ['type' => 'string', 'format' => 'email'],
            'phone' => ['type' => 'string', 'pattern' => self::PHONE_PATTERN],
            'number', 'money' => self::decimal(),
            'date' => ['type' => 'string', 'format' => 'date'],
            'time' => ['type' => 'string', 'pattern' => self::TIME_PATTERN],
            'datetime' => self::dateTime(),
            'select', 'radio' => self::options($element),
            'multiselect' => ['type' => 'array', 'items' => self::object(self::options($element)), 'uniqueItems' => true],
            'checkbox', 'toggle' => ['type' => 'boolean'],
            'rating' => ['type' => 'integer', 'minimum' => 1, 'maximum' => $element->int('max', 5)],
            'photo', 'file' => self::attachment($element),
            'signature' => self::signature(),
            'location' => self::location(),
            'calculated' => $element->format() === 'text'
                ? ['type' => ['string', 'null']]
                : ['type' => ['string', 'null'], 'pattern' => self::DECIMAL_PATTERN],
            'hidden' => ['type' => ['string', 'integer', 'boolean']],
            'repeater' => self::repeater($definition, $element),
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $schema
     */
    private static function annotate(Element $element, array $schema): stdClass
    {
        $label = $element->get('label');
        $role = $element->get('role');

        return self::object([
            ...$schema,
            ...(is_string($label) ? ['title' => $label] : []),
            ...(is_string($role) ? ['x-role' => $role] : []),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private static function decimal(): array
    {
        return ['type' => 'string', 'pattern' => self::DECIMAL_PATTERN];
    }

    /**
     * @return array<string, mixed>
     */
    private static function dateTime(): array
    {
        return ['type' => 'string', 'format' => 'date-time'];
    }

    /**
     * @return array<string, mixed>
     */
    private static function uuid(): array
    {
        return ['type' => 'string', 'format' => 'uuid'];
    }

    /**
     * @return array<string, mixed>
     */
    private static function options(Element $element): array
    {
        return ['type' => 'string', 'enum' => array_values(array_unique($element->optionValues()))];
    }

    /**
     * @return array<string, mixed>
     */
    private static function attachment(Element $element): array
    {
        return $element->get('multiple') === true
            ? ['type' => 'array', 'items' => self::object(self::uuid())]
            : self::uuid();
    }

    /**
     * @return array<string, mixed>
     */
    private static function signature(): array
    {
        return self::members([
            'attachment' => self::uuid(),
            'signerName' => ['type' => 'string'],
            'signedAt' => self::dateTime(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private static function location(): array
    {
        return self::members([
            'lat' => ['type' => 'number', 'minimum' => -90, 'maximum' => 90],
            'lng' => ['type' => 'number', 'minimum' => -180, 'maximum' => 180],
            'accuracy' => ['type' => 'number', 'minimum' => 0],
            'capturedAt' => self::dateTime(),
        ]);
    }

    /**
     * @param  array<string, array<string, mixed>>  $properties
     * @return array<string, mixed>
     */
    private static function members(array $properties): array
    {
        return [
            'type' => 'object',
            'properties' => self::object(array_map(self::object(...), $properties)),
            'required' => array_keys($properties),
            'additionalProperties' => false,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function repeater(Definition $definition, Element $repeater): array
    {
        $rowScope = $definition->scope($repeater->rowScopeId());
        $items = $rowScope === null ? new stdClass : self::object(self::scope($definition, $rowScope));

        return ['type' => 'array', 'items' => $items];
    }

    /**
     * @param  array<string, mixed>  $properties
     */
    private static function object(array $properties): stdClass
    {
        $object = new stdClass;

        foreach ($properties as $key => $value) {
            $object->{$key} = $value;
        }

        return $object;
    }
}
