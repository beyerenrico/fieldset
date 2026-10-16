<?php

declare(strict_types=1);

namespace FormSchema\Lint\Rules;

use FormSchema\Definition\Definition;
use FormSchema\Definition\DependencyGraph;
use FormSchema\Lint\LintFinding;
use FormSchema\Lint\LintRule;

/**
 * cycle: reported once per cycle, at the first calculated element of the cycle in document order.
 */
final class CycleRule implements LintRule
{
    public function check(Definition $definition, array $published): array
    {
        $findings = [];

        foreach (DependencyGraph::build($definition)->cycleHeads() as $head) {
            $findings[] = LintFinding::error('cycle', $head->path.'/expression', ['key' => $head->key]);
        }

        return $findings;
    }
}
