<?php

declare(strict_types=1);

namespace FormSchema\Lint\Rules;

use FormSchema\Definition\Definition;
use FormSchema\Lint\LintFinding;
use FormSchema\Lint\LintRule;
use FormSchema\Lint\RoleVocabulary;

/**
 * unknown_role (warning) and duplicate_role (per scope; repeater rows are their own scope).
 */
final readonly class RoleRule implements LintRule
{
    public function __construct(private RoleVocabulary $vocabulary) {}

    public function check(Definition $definition, array $published): array
    {
        $findings = [];
        $seen = [];

        foreach ($definition->elements as $element) {
            $role = $element->get('role');

            if (! is_string($role)) {
                continue;
            }

            if (! $this->vocabulary->contains($role)) {
                $findings[] = LintFinding::warning('unknown_role', $element->path.'/role', ['role' => $role]);
            }

            if (isset($seen[$element->scopeId][$role])) {
                $findings[] = LintFinding::error('duplicate_role', $element->path.'/role', ['role' => $role]);
            }

            $seen[$element->scopeId][$role] = true;
        }

        return $findings;
    }
}
