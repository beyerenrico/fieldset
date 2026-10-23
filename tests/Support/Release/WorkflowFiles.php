<?php

declare(strict_types=1);

namespace Tests\Support\Release;

/**
 * Reads GitHub Actions workflows as text for the guards actionlint does not check (ReleaseWorkflowTest,
 * DocsWorkflowTest): job blocks, action pins and `run:` scripts. The workflows keep one layout (jobs indented by two
 * spaces, steps by six), so text is enough and no YAML parser is needed.
 */
final class WorkflowFiles
{
    /** ci.yml's jobs from before 2.0, which keep their tags (2.0 plan U7: existing CI jobs unchanged). */
    public const array LEGACY_CI_JOBS = ['lint', 'static', 'php-tests', 'js-tests', 'conformance', 'e2e', 'audit', 'image'];

    public static function text(string $file): string
    {
        return (string) file_get_contents(base_path($file));
    }

    /** @return array<string, string> job id => its block of the workflow text */
    public static function jobs(string $workflow): array
    {
        $jobs = substr($workflow, (int) strpos($workflow, "\njobs:\n") + 7);
        preg_match_all('/^  ([A-Za-z_][\w-]*):\n(.*?)(?=^  [A-Za-z_][\w-]*:\n|\z)/ms', $jobs, $matches, PREG_SET_ORDER);

        return array_column($matches, 2, 1);
    }

    /**
     * Every `uses:` of a workflow's jobs must be an action pinned by full commit SHA with a version comment, or a workflow
     * of the same commit. Only ci.yml's jobs from before 2.0 may use a major tag (`@vN`).
     *
     * @return array{checked: int, unpinned: list<string>} the pinned actions checked, and "job: line" of every other use
     */
    public static function actionPins(string $file, string $workflow): array
    {
        $legacy = $file === '.github/workflows/ci.yml' ? self::LEGACY_CI_JOBS : [];
        $checked = 0;
        $unpinned = [];

        foreach (self::jobs($workflow) as $job => $block) {
            preg_match_all('/^\s+(?:-\s+)?uses:\s+(\S+)(.*)$/m', $block, $uses, PREG_SET_ORDER);
            foreach ($uses as [$line, $action, $comment]) {
                if (in_array($job, $legacy, true) && preg_match('/@v\d+$/', $action) === 1) {
                    continue;
                }
                $sameCommit = str_starts_with($action, './');
                $checked += $sameCommit ? 0 : 1;
                $pinned = $sameCommit
                    ? preg_match('/^\.\/\.github\/workflows\/[\w-]+\.yml$/', $action) === 1
                    : preg_match('/^[\w.-]+\/[\w.-]+@[0-9a-f]{40}$/', $action) === 1 && preg_match('/^ # v?\d+\.\d+\.\d+$/', $comment) === 1;
                if (! $pinned) {
                    $unpinned[] = "{$job}: ".trim($line);
                }
            }
        }

        return ['checked' => $checked, 'unpinned' => $unpinned];
    }

    /** @return list<string> the `run:` scripts of a job block, one-line and literal block scalars */
    public static function runScripts(string $job): array
    {
        // A block scalar's lines are indented deeper than its `run:` key (two more columns after a list dash).
        preg_match_all('/^( +)(- )?run: (?:\|\n((?:\1(?(2)  ) +\S.*\n|\n)*)|(.+)\n)/m', $job, $runs, PREG_SET_ORDER);

        return array_map(fn (array $run): string => ($run[3] ?? '') !== '' ? $run[3] : ($run[4] ?? ''), $runs);
    }

    /** @return int how many `run:` keys the block has, to prove runScripts() read every one */
    public static function runCount(string $job): int
    {
        return preg_match_all('/^ +(?:- )?run: /m', $job);
    }

    /** @return list<string> the steps of a job block, each from its `- ` line to the next step */
    public static function steps(string $job): array
    {
        preg_match_all('/^      - .*?(?=^      - |\z)/ms', $job, $steps);

        return $steps[0];
    }
}
