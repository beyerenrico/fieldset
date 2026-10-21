<?php

declare(strict_types=1);

namespace Fieldset\Evaluation;

use Fieldset\Definition\Element;
use Fieldset\Logic\Decimal;
use Fieldset\Logic\Js;

/**
 * Converts an expression result into the stored value of a calculated element (§5.4).
 */
final class CalculatedValue
{
    public static function convert(Element $element, mixed $result): ?string
    {
        if ($element->format() === 'text') {
            return $result === null ? null : (is_string($result) ? $result : Js::catString($result));
        }

        $decimal = Decimal::parse($result);

        return $decimal === null ? null : Decimal::format(Decimal::toScale($decimal, $element->scale()));
    }
}
