<?php

declare(strict_types=1);

namespace Fieldset\Logic;

final class UnknownOperatorException extends LogicError
{
    public function __construct(string $operator)
    {
        parent::__construct(self::UNKNOWN_OPERATOR, $operator, sprintf('Unknown JsonLogic operator "%s".', $operator));
    }
}
