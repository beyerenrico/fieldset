<?php

declare(strict_types=1);

namespace Fieldset\Logic;

final class NestingTooDeepException extends LogicError
{
    public function __construct(int $limit)
    {
        parent::__construct(self::MAX_DEPTH, '', sprintf('Logic nesting exceeds %d levels.', $limit));
    }
}
