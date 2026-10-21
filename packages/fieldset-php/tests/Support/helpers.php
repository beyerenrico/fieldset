<?php

declare(strict_types=1);

use Fieldset\Evaluation\EvaluationContext;
use Fieldset\Evaluation\ValidationError;
use Fieldset\Json\Json;
use Fieldset\Lint\LintFinding;

/*
 * Shared helpers for the fieldset-php test suite (plain Pest, no Laravel).
 */

if (! function_exists('fs_tree')) {
    /**
     * Converts PHP values into a JSON tree (associative arrays become stdClass objects).
     * Use `new stdClass` for an empty object; `[]` stays an empty array.
     */
    function fs_tree(mixed $value): mixed
    {
        return Json::decode(json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION));
    }

    /**
     * An element array with a unique UUID id.
     *
     * @param  array<string, mixed>  $properties
     * @return array<string, mixed>
     */
    function fs_element(string $type, string $key, array $properties = []): array
    {
        static $counter = 0;
        $counter++;

        return ['id' => sprintf('00000000-0000-4000-8000-%012d', $counter), 'type' => $type, 'key' => $key, ...$properties];
    }

    /**
     * A one-page definition JSON tree around the given element arrays.
     *
     * @param  list<array<string, mixed>>  $elements
     * @param  array<string, mixed>  $extra  merged into the definition root
     */
    function fs_definition(array $elements, array $extra = []): stdClass
    {
        $definition = fs_tree([
            'specVersion' => '1.0.0',
            'key' => 'test',
            'title' => 'Test',
            'locale' => 'de-DE',
            'pages' => [['key' => 'p', 'title' => 'P', 'elements' => $elements]],
            ...$extra,
        ]);
        assert($definition instanceof stdClass);

        return $definition;
    }

    /**
     * The evaluation context used by the conformance suite.
     */
    function fs_context(): EvaluationContext
    {
        return new EvaluationContext('2026-10-16', '2026-10-16T09:41:00+02:00', 'Willi Worker', 'worker@demo.test', 'Demo GmbH', 3);
    }

    function fs_conformance_dir(): string
    {
        return dirname(__DIR__, 4).'/spec/conformance';
    }

    /**
     * One dataset entry per case of every JSON file in a conformance folder.
     *
     * @return array<string, array{stdClass, stdClass}> "file › description" => [suite, case]
     */
    function fs_conformance_cases(string $folder): array
    {
        $cases = [];
        $files = glob(fs_conformance_dir().'/'.$folder.'/*.json') ?: [];
        sort($files);

        foreach ($files as $file) {
            $suite = Json::decode((string) file_get_contents($file));
            assert($suite instanceof stdClass);

            foreach ($suite->tests as $case) {
                $cases[$folder.'/'.basename($file).' › '.$case->description] = [$suite, $case];
            }
        }

        return $cases;
    }

    /**
     * Errors as a sorted, de-duplicated list of "pointer|code".
     *
     * @param  list<ValidationError|stdClass>  $errors
     * @return list<string>
     */
    function fs_error_set(array $errors): array
    {
        $set = array_map(static fn (ValidationError|stdClass $e): string => $e->pointer.'|'.$e->code, $errors);
        $set = array_values(array_unique($set));
        sort($set);

        return $set;
    }

    /**
     * Lint findings as a sorted, de-duplicated list of "severity|code|path".
     *
     * @param  list<LintFinding|stdClass>  $findings
     * @return list<string>
     */
    function fs_finding_set(array $findings): array
    {
        $set = array_map(static fn (LintFinding|stdClass $f): string => $f->severity.'|'.$f->code.'|'.$f->path, $findings);
        $set = array_values(array_unique($set));
        sort($set);

        return $set;
    }
}
