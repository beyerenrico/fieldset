<?php

declare(strict_types=1);

namespace Fieldset\Lint\Rules;

use Fieldset\Definition\Definition;
use Fieldset\Lint\LintFinding;
use Fieldset\Lint\LintRule;
use Fieldset\Lint\NumberingFormat;
use stdClass;

/**
 * invalid_numbering_format (§7.3).
 */
final class NumberingRule implements LintRule
{
    public function check(Definition $definition, array $published): array
    {
        $numbering = $definition->settings()->numbering ?? null;

        if (! $numbering instanceof stdClass) {
            return [];
        }

        $format = $numbering->format ?? null;
        $reset = $numbering->reset ?? null;
        $reset = is_string($reset) ? $reset : 'never';

        if (! is_string($format) || NumberingFormat::isValid($format, $reset)) {
            return [];
        }

        return [LintFinding::error('invalid_numbering_format', '/settings/numbering/format', ['format' => $format, 'reset' => $reset])];
    }
}
