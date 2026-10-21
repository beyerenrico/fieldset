<?php

declare(strict_types=1);

namespace Fieldset\Lint;

use Fieldset\Definition\Definition;

interface LintRule
{
    /**
     * @param  list<Definition>  $published  earlier published versions
     * @return list<LintFinding>
     */
    public function check(Definition $definition, array $published): array;
}
