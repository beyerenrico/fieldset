<?php

declare(strict_types=1);

namespace FormSchema\Evaluation;

use FormSchema\Definition\Element;
use FormSchema\Logic\Js;

/**
 * Step 4 of §5.5: validates every visible data element in document order, rows in index order.
 */
final class SubmissionValidator
{
    /**
     * @return list<ValidationError>
     */
    public static function validate(EvaluationState $state): array
    {
        $errors = [];

        foreach ($state->instances() as $instance) {
            $element = $instance->element;

            if (! $element->isData() || $element->isCalculated() || ! $state->isVisible($instance->pointer)) {
                continue;
            }

            $value = $state->value($instance->rowPath, $element->key);
            $violation = ElementValidator::validate($element, $value, self::isRequired($state, $element, $instance));

            if ($violation !== null) {
                $errors[] = $violation->at($instance->pointer);
            }
        }

        return $errors;
    }

    private static function isRequired(EvaluationState $state, Element $element, Instance $instance): bool
    {
        if ($element->get('required') === true) {
            return true;
        }

        return $element->has('requiredIf')
            && Js::truthy($state->evaluate($element->get('requiredIf'), $instance->rowPath));
    }
}
