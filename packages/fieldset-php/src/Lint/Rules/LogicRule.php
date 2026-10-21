<?php

declare(strict_types=1);

namespace Fieldset\Lint\Rules;

use Fieldset\Definition\Definition;
use Fieldset\Definition\Element;
use Fieldset\Definition\Reference;
use Fieldset\Definition\ReferenceResolver;
use Fieldset\Lint\LintFinding;
use Fieldset\Lint\LintRule;
use Fieldset\Logic\LogicDepth;

/**
 * Checks every logic property (logic_too_deep, unknown_reference, unknown_operator,
 * decimal_builtin_operator, float_literal) and `signerNameFrom` references.
 */
final class LogicRule implements LintRule
{
    private const ELEMENT_LOGIC = ['visibleIf', 'requiredIf', 'readOnlyIf', 'default', 'expression'];

    public function check(Definition $definition, array $published): array
    {
        $linter = new LogicLinter($definition);
        $findings = [];

        foreach ($definition->pages as $page) {
            if ($page->has('visibleIf')) {
                array_push($findings, ...self::property($linter, $page->get('visibleIf'), '', $page->path.'/visibleIf'));
            }
        }

        foreach ($definition->elements as $element) {
            foreach (self::logicProperties($element) as $property) {
                array_push(
                    $findings,
                    ...self::property($linter, $element->get($property), $element->scopeId, $element->path.'/'.$property),
                );
            }

            array_push($findings, ...self::signerName($definition, $element));
        }

        return $findings;
    }

    /**
     * Logic nested deeper than 512 levels is reported as `logic_too_deep` and not walked further.
     *
     * @return list<LintFinding>
     */
    private static function property(LogicLinter $linter, mixed $logic, string $scopeId, string $path): array
    {
        if (LogicDepth::exceeds($logic)) {
            return [LintFinding::error('logic_too_deep', $path)];
        }

        return $linter->lint($logic, $scopeId, $path);
    }

    /**
     * @return list<string>
     */
    private static function logicProperties(Element $element): array
    {
        return array_values(array_filter(self::ELEMENT_LOGIC, $element->has(...)));
    }

    /**
     * @return list<LintFinding>
     */
    private static function signerName(Definition $definition, Element $element): array
    {
        $source = $element->get('signerNameFrom');

        if ($element->type !== 'signature' || ! is_string($source)) {
            return [];
        }

        $reference = (new ReferenceResolver($definition))->resolve($source, $element->scopeId);

        if ($reference->kind === Reference::ELEMENT && $reference->target === $reference->element) {
            return [];
        }

        return [LintFinding::error('unknown_reference', $element->path.'/signerNameFrom', ['reference' => $source])];
    }
}
