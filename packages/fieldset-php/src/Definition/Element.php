<?php

declare(strict_types=1);

namespace Fieldset\Definition;

use Fieldset\Json\JsNumber;
use stdClass;

/**
 * An element of a definition. Keeps the raw JSON object for lossless output.
 */
final readonly class Element
{
    /**
     * @param  string  $path  JSON pointer into the definition, e.g. `/pages/0/elements/2`
     * @param  string  $scopeId  '' for the root scope, otherwise the path of the enclosing repeater
     * @param  string|null  $parentPath  path of the enclosing container element; null at page level
     * @param  list<Element>  $children  direct children (section/repeater elements, columns flattened)
     */
    public function __construct(
        public stdClass $raw,
        public string $id,
        public string $type,
        public string $key,
        public string $path,
        public string $scopeId,
        public int $pageIndex,
        public ?string $parentPath,
        public array $children,
    ) {}

    public function has(string $property): bool
    {
        return property_exists($this->raw, $property);
    }

    public function get(string $property, mixed $default = null): mixed
    {
        return $this->has($property) ? $this->raw->{$property} : $default;
    }

    /**
     * An integer property (integral JSON numbers such as `3.0` count), or $default.
     */
    public function int(string $property, int $default): int
    {
        return JsNumber::safeInteger($this->get($property)) ?? $default;
    }

    public function label(): ?string
    {
        $label = $this->get('label');

        return is_string($label) ? $label : null;
    }

    public function isLayout(): bool
    {
        return ElementType::isLayout($this->type);
    }

    /**
     * True for elements that carry submission data: every non-layout type with a type name
     * (calculated, hidden, repeater, and unknown or reserved types too).
     */
    public function isData(): bool
    {
        return ElementType::isData($this->type);
    }

    public function isRepeater(): bool
    {
        return $this->type === 'repeater';
    }

    public function isCalculated(): bool
    {
        return $this->type === 'calculated';
    }

    /**
     * Scope id of the rows of this repeater.
     */
    public function rowScopeId(): string
    {
        return $this->path;
    }

    /**
     * Calculated format: `number` (default), `money` or `text`.
     */
    public function format(): string
    {
        $format = $this->get('format');

        return is_string($format) && in_array($format, ['number', 'money', 'text'], true) ? $format : 'number';
    }

    /**
     * True for number, money and calculated elements with a numeric format.
     */
    public function isDecimal(): bool
    {
        return in_array($this->type, ElementType::DECIMAL, true)
            || ($this->isCalculated() && $this->format() !== 'text');
    }

    /**
     * Fraction digits of money values (0–4, default 2) or numeric calculated values (0–10,
     * default 2 for money format, 6 otherwise). Out-of-range values fall back to the default.
     *
     * @return int<0, max>
     */
    public function scale(): int
    {
        [$max, $default] = match (true) {
            $this->type === 'money' => [4, 2],
            $this->isCalculated() && $this->format() === 'money' => [10, 2],
            default => [10, 6],
        };

        $scale = $this->int('scale', $default);

        return $scale >= 0 && $scale <= $max ? $scale : $default;
    }

    /**
     * @return list<string> option values in definition order
     */
    public function optionValues(): array
    {
        $options = $this->get('options');
        $values = [];

        foreach (is_array($options) ? $options : [] as $option) {
            if ($option instanceof stdClass && isset($option->value) && is_string($option->value)) {
                $values[] = $option->value;
            }
        }

        return $values;
    }

    /**
     * The `validation` object, or an empty one.
     */
    public function validation(): stdClass
    {
        $validation = $this->get('validation');

        return $validation instanceof stdClass ? $validation : new stdClass;
    }
}
