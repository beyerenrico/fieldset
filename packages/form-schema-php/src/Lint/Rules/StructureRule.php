<?php

declare(strict_types=1);

namespace FormSchema\Lint\Rules;

use FormSchema\Definition\Definition;
use FormSchema\Definition\Element;
use FormSchema\Definition\ElementType;
use FormSchema\Json\JsNumber;
use FormSchema\Lint\LintFinding;
use FormSchema\Lint\LintRule;
use FormSchema\Support\EcmaRegex;

/**
 * reserved_type, invalid_repeater_bounds, invalid_pattern, empty_page.
 */
final class StructureRule implements LintRule
{
    public function check(Definition $definition, array $published): array
    {
        $findings = [];

        foreach ($definition->pages as $page) {
            $elements = $page->get('elements');

            if (! is_array($elements) || $elements === []) {
                $findings[] = LintFinding::warning('empty_page', $page->path);
            }
        }

        foreach ($definition->elements as $element) {
            array_push($findings, ...self::element($element));
        }

        return $findings;
    }

    /**
     * @return list<LintFinding>
     */
    private static function element(Element $element): array
    {
        $findings = [];

        if (ElementType::isReserved($element->type)) {
            $findings[] = LintFinding::error('reserved_type', $element->path.'/type', ['type' => $element->type]);
        }

        $min = JsNumber::safeInteger($element->get('minItems'));
        $max = JsNumber::safeInteger($element->get('maxItems'));

        if ($element->isRepeater() && $min !== null && $max !== null && $min > $max) {
            $findings[] = LintFinding::error('invalid_repeater_bounds', $element->path, ['minItems' => $min, 'maxItems' => $max]);
        }

        $pattern = $element->validation()->pattern ?? null;

        if (is_string($pattern) && ! EcmaRegex::isValid($pattern)) {
            $findings[] = LintFinding::error('invalid_pattern', $element->path.'/validation/pattern', ['pattern' => $pattern]);
        }

        return $findings;
    }
}
