<?php

declare(strict_types=1);

namespace FormSchema\Logic;

use RuntimeException;

/**
 * Evaluation error of the logic dialect: an unknown operator or nesting deeper than
 * JsonLogic::MAX_DEPTH. Evaluators treat the whole expression as null (README §4.2).
 */
class LogicError extends RuntimeException
{
    public const UNKNOWN_OPERATOR = 'unknown_operator';

    public const MAX_DEPTH = 'max_depth';

    public function __construct(
        public readonly string $reason,
        public readonly string $operator,
        string $message,
    ) {
        parent::__construct($message);
    }
}
