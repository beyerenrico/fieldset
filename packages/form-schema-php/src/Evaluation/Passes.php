<?php

declare(strict_types=1);

namespace FormSchema\Evaluation;

use FormSchema\Definition\Element;
use FormSchema\Logic\Js;

/**
 * The passes of §5.5: visibility (2a), defaults (2b), calculations (2c) and stripping (3).
 */
final class Passes
{
    /**
     * Top-down in document order: visible when the own `visibleIf` is truthy and the parent is visible.
     */
    public static function visibility(EvaluationState $state): void
    {
        $pages = [];

        foreach ($state->instances() as $instance) {
            $parentVisible = $instance->parentPointer === null
                ? ($pages[$instance->pageIndex] ??= self::pageVisible($state, $instance->pageIndex))
                : $state->isVisible($instance->parentPointer);

            $state->setVisible($instance->pointer, $parentVisible && self::ownVisible($state, $instance));
        }
    }

    /**
     * Applies `default` to visible data elements whose value is missing or null (and to `hidden`
     * elements whose client value is not a string, integer or boolean).
     */
    public static function defaults(EvaluationState $state): void
    {
        foreach ($state->instances() as $instance) {
            $element = $instance->element;

            if (! self::wantsDefault($state, $instance)) {
                continue;
            }

            $value = Normalizer::defaultValue(
                $state->definition,
                $element,
                $state->evaluate($element->get('default'), $instance->rowPath),
            );

            if ($value !== null) {
                $state->set($instance->rowPath, $element->key, $value);
            }
        }
    }

    /**
     * Computes calculated values in dependency order; invisible ones are null.
     *
     * @param  list<Element>  $order
     */
    public static function calculations(EvaluationState $state, array $order): void
    {
        foreach ($order as $calculated) {
            $updates = [];
            $rowPaths = InstanceWalker::rowPaths($state->definition->repeaterChain($calculated), $state->values());

            foreach ($rowPaths as $rowPath) {
                $visible = $state->isVisible(Instance::pointerFor($rowPath, $calculated->key));
                $result = $visible ? $state->evaluate($calculated->get('expression'), $rowPath) : null;
                $updates[] = [$rowPath, $visible ? CalculatedValue::convert($calculated, $result) : null];
            }

            foreach ($updates as [$rowPath, $value]) {
                if (! $state->has($rowPath, $calculated->key) || $state->value($rowPath, $calculated->key) !== $value) {
                    $state->set($rowPath, $calculated->key, $value);
                }
            }
        }
    }

    /**
     * Removes the values of invisible data elements (invisible repeaters with their rows).
     */
    public static function strip(EvaluationState $state): void
    {
        foreach (array_reverse($state->instances()) as $instance) {
            if ($instance->element->isData() && ! $state->isVisible($instance->pointer)) {
                $state->remove($instance->rowPath, $instance->element->key);
            }
        }
    }

    private static function wantsDefault(EvaluationState $state, Instance $instance): bool
    {
        $element = $instance->element;

        if (! $element->isData() || $element->isCalculated() || ! $element->has('default')
            || ! $state->isVisible($instance->pointer)) {
            return false;
        }

        $value = $state->value($instance->rowPath, $element->key);

        return $value === null || ($element->type === 'hidden' && ! TypeChecker::isHiddenValue($value));
    }

    private static function pageVisible(EvaluationState $state, int $pageIndex): bool
    {
        $page = $state->definition->page($pageIndex);

        if ($page === null || ! $page->has('visibleIf')) {
            return true;
        }

        return Js::truthy($state->evaluate($page->get('visibleIf'), []));
    }

    private static function ownVisible(EvaluationState $state, Instance $instance): bool
    {
        if (! $instance->element->has('visibleIf')) {
            return true;
        }

        return Js::truthy($state->evaluate($instance->element->get('visibleIf'), $instance->rowPath));
    }
}
