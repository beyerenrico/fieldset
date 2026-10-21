<?php

declare(strict_types=1);

namespace App\Domain\Workflows\Triggers;

use Fieldset\Json\Json;
use Fieldset\Logic\Js;
use Fieldset\Logic\JsonLogic;
use Fieldset\Logic\LogicError;
use stdClass;

/**
 * Workflow conditions: JsonLogic (the Fieldset dialect, ADR 0021) over the run context
 * `{ submission: { data, status, number, form_key, version, submitted_at, … }, form: {…}, trigger: {…} }`.
 * No conditions (null, `{}` or `[]`) always match; an evaluation error never matches.
 */
final readonly class ConditionEvaluator
{
    public function __construct(private JsonLogic $logic) {}

    /** @param  array<string, mixed>  $context */
    public function passes(mixed $conditions, array $context): bool
    {
        if (self::isEmpty($conditions)) {
            return true;
        }

        return Js::truthy($this->logic->evaluate($conditions, Json::decode(Json::encode($context))));
    }

    /** Null when the expression is usable, else the error (unknown operator, too deep) for the editor. */
    public function problem(mixed $conditions): ?string
    {
        if (self::isEmpty($conditions)) {
            return null;
        }
        try {
            $this->logic->evaluateStrict($conditions, Json::decode(Json::encode(RunContext::sample())));
        } catch (LogicError $e) {
            return $e->getMessage();
        }

        return null;
    }

    public static function isEmpty(mixed $conditions): bool
    {
        return $conditions === null || $conditions === [] || ($conditions instanceof stdClass && get_object_vars($conditions) === []);
    }
}
