<?php

declare(strict_types=1);

namespace Fieldset\Lint;

use Fieldset\Definition\Definition;
use Fieldset\Definition\InvalidDefinitionException;
use Fieldset\Lint\Rules\CycleRule;
use Fieldset\Lint\Rules\DuplicateRule;
use Fieldset\Lint\Rules\HistoryRule;
use Fieldset\Lint\Rules\LogicRule;
use Fieldset\Lint\Rules\NumberingRule;
use Fieldset\Lint\Rules\RoleRule;
use Fieldset\Lint\Rules\StructureRule;
use stdClass;

/**
 * Semantic checks of spec §7. A definition with lint errors MUST NOT be published.
 */
final readonly class Linter
{
    /** @var list<LintRule> */
    private array $rules;

    /**
     * @param  string|null  $rolesPath  roles.json to use instead of the bundled vocabulary
     */
    public function __construct(?string $rolesPath = null)
    {
        $this->rules = [
            new DuplicateRule,
            new LogicRule,
            new CycleRule,
            new NumberingRule,
            new StructureRule,
            new RoleRule(RoleVocabulary::load($rolesPath)),
            new HistoryRule,
        ];
    }

    /**
     * @param  mixed  $definition  definition JSON tree (or a Definition)
     * @param  list<mixed>  $published  earlier published definitions (JSON trees); non-objects are ignored
     * @return list<LintFinding>
     *
     * @throws InvalidDefinitionException when the definition is not a JSON object
     */
    public function lint(mixed $definition, array $published = []): array
    {
        $parsed = Definition::fromJson($definition);
        $history = [];

        foreach ($published as $version) {
            if ($version instanceof stdClass || $version instanceof Definition) {
                $history[] = Definition::fromJson($version);
            }
        }

        $findings = [];
        foreach ($this->rules as $rule) {
            array_push($findings, ...$rule->check($parsed, $history));
        }

        return self::unique($findings);
    }

    /**
     * True when no finding is an error.
     *
     * @param  list<LintFinding>  $findings
     */
    public static function isPublishable(array $findings): bool
    {
        foreach ($findings as $finding) {
            if ($finding->isError()) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  list<LintFinding>  $findings
     * @return list<LintFinding>
     */
    private static function unique(array $findings): array
    {
        $unique = [];

        foreach ($findings as $finding) {
            $unique[serialize($finding->toArray())] ??= $finding;
        }

        return array_values($unique);
    }
}
