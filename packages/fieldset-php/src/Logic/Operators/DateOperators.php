<?php

declare(strict_types=1);

namespace Fieldset\Logic\Operators;

use Fieldset\Logic\DateMath;

/**
 * date.diff and date.add (§5.3).
 */
final class DateOperators extends EagerOperation
{
    public function names(): array
    {
        return ['date.diff', 'date.add'];
    }

    protected function compute(string $operator, array $values, mixed $data): mixed
    {
        if ($operator === 'date.diff') {
            return DateMath::diff($values[0] ?? null, $values[1] ?? null, $values[2] ?? null);
        }

        return DateMath::add($values[0] ?? null, $values[1] ?? null, $values[2] ?? null);
    }
}
