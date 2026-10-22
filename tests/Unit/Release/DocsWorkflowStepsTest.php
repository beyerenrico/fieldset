<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;
use Tests\Support\Release\WorkflowFiles;

/*
 * Two run scripts of .github/workflows/docs.yml executed with stub commands (docs site plan U7): build's reconcile
 * warning, which must not ask for an older release than the one the run publishes, and deploy's choice of the commit
 * to deploy, which must not roll Pages back to a commit that is no longer the head of docs-site. DocsWorkflowTest
 * guards the workflow's structure.
 */

const DOCS_STEPS_DIR = 'framework/testing/docs-steps';

afterEach(function (): void {
    File::deleteDirectory(storage_path(DOCS_STEPS_DIR));
});

/**
 * Runs the run script of a docs.yml step as Actions does (bash -eo pipefail) in a scratch directory that holds
 * docs/site/store/version-order.jq, with each stub as an executable in bin/ before PATH.
 *
 * @param  array<string, string>  $env
 * @param  array<string, string>  $stubs  command => bash script body
 */
function runDocsStep(string $job, string $step, array $env, array $stubs = []): Process
{
    $steps = WorkflowFiles::steps(WorkflowFiles::jobs(WorkflowFiles::text('.github/workflows/docs.yml'))[$job]);
    $block = array_values(array_filter($steps, fn (string $candidate): bool => str_starts_with($candidate, "      - name: {$step}\n")))[0] ?? '';
    $dir = storage_path(DOCS_STEPS_DIR);
    File::deleteDirectory($dir);
    File::ensureDirectoryExists("{$dir}/bin");
    File::ensureDirectoryExists("{$dir}/docs/site/store");
    File::copy(base_path('docs/site/store/version-order.jq'), "{$dir}/docs/site/store/version-order.jq");
    File::put("{$dir}/step.sh", WorkflowFiles::runScripts($block)[0] ?? 'exit 99');
    foreach ($stubs as $command => $body) {
        File::put("{$dir}/bin/{$command}", "#!/usr/bin/env bash\n{$body}\n");
        chmod("{$dir}/bin/{$command}", 0755);
    }
    $env = ['PATH' => "{$dir}/bin:".getenv('PATH'), 'GITHUB_OUTPUT' => "{$dir}/output", 'GITHUB_REPOSITORY' => 'beyerenrico/fieldset', ...$env];

    return tap(new Process(['bash', '--noprofile', '--norc', '-eo', 'pipefail', 'step.sh'], $dir, $env))->run();
}

/** @return list<string> the ::warning:: lines of a run, without the prefix */
function docsWarnings(Process $process): array
{
    preg_match_all('/^::warning::(.*)$/m', $process->getOutput(), $warnings);

    return $warnings[1];
}

/** The reconcile step for a run publishing $tag, with docs-site storing $stored and $releases published. */
function runReconcile(string $tag, array $stored, array $releases): Process
{
    $file = 'none';
    if ($stored !== []) {
        $file = storage_path(DOCS_STEPS_DIR.'-stored.json');
        $copies = array_map(fn (string $copy): array => ['minor' => implode('.', array_slice(explode('.', substr($copy, 1)), 0, 2)), 'tag' => $copy], $stored);
        File::put($file, (string) json_encode(['schema' => 1, 'copies' => $copies]));
    }
    $process = runDocsStep('build', 'Warn about published releases newer than their stored copy (reconcile)',
        ['TAG' => $tag, 'STORED' => $file, 'RELEASES' => implode("\n", $releases)],
        ['gh' => '[ "$1 $2" = "release list" ] || exit 2; printf "%s\n" "$RELEASES"']);
    File::delete(storage_path(DOCS_STEPS_DIR.'-stored.json'));

    return $process;
}

it('warns about published releases newer than their stored copy, counting the release this run publishes', function (string $tag, array $stored, array $releases, array $warnings): void {
    $reconcile = runReconcile($tag, $stored, $releases);

    expect($reconcile->getExitCode())->toBe(0, $reconcile->getErrorOutput())
        ->and(docsWarnings($reconcile))->toBe($warnings);
})->with([
    'the final of a minor without a copy' => ['v2.1.0', [], ['v2.1.0', 'v2.1.0-rc.1'], []],
    'the next rc over a stored rc' => ['v2.1.0-rc.2', ['v2.1.0-rc.1'], ['v2.1.0-rc.2', 'v2.1.0-rc.1'], []],
    'a newer rc than the one this run publishes' => ['v2.1.0-rc.2', ['v2.1.0-rc.1'], ['v2.1.0-rc.3', 'v2.1.0-rc.2', 'v2.1.0-rc.1'],
        ['v2.1.0-rc.3 is published, but 2.1 stores v2.1.0-rc.2: dispatch publish v2.1.0-rc.3']],
    'an older release than the stored copy' => ['v2.1.0', ['v2.1.1'], ['v2.1.2', 'v2.1.1', 'v2.1.0'],
        ['v2.1.2 is published, but 2.1 stores v2.1.1: dispatch publish v2.1.2']],
    'an rc while the minor has a final' => ['v2.1.1-rc.1', ['v2.1.0'], ['v2.1.1-rc.1', 'v2.1.0', 'v2.1.0-rc.1'], []],
    'an alpha, which gets no copy' => ['v2.2.0-alpha.1', ['v2.1.0'], ['v2.2.0-alpha.1', 'v2.2.0-rc.1', 'v2.1.0'],
        ['v2.2.0-rc.1 is published, but 2.2 has no stored copy: dispatch publish v2.2.0-rc.1']],
    'another minor' => ['v2.2.0-rc.1', ['v2.1.0'], ['v2.2.0-rc.1', 'v2.1.1', 'v2.1.0', 'v2.0.5'],
        ['v2.1.1 is published, but 2.1 stores v2.1.0: dispatch publish v2.1.1']],
]);

/** Deploy's "Choose the commit to deploy" with git ls-remote reporting $head for docs-site (status $status). */
function runChooseCommit(array $env, string $head, int $status = 0): Process
{
    return runDocsStep('deploy', 'Choose the commit to deploy', ['GITHUB_SERVER_URL' => 'https://github.com', 'HEAD_SHA' => $head, ...$env],
        ['git' => 'echo "$*" >> git.log; [ "$1" = ls-remote ] || exit 2; [ '.$status.' -eq 0 ] || exit '.$status.'; printf "%s\trefs/heads/docs-site\n" "$HEAD_SHA"']);
}

function chosenRef(): string
{
    return trim((string) @file_get_contents(storage_path(DOCS_STEPS_DIR.'/output')));
}

it('deploys the stored commit while it is the head of docs-site', function (): void {
    $stored = str_repeat('a', 40);
    $choose = runChooseCommit(['EVENT' => 'workflow_run', 'MODE' => '', 'STORED' => $stored], $stored);

    expect($choose->getExitCode())->toBe(0, $choose->getOutput())
        ->and(chosenRef())->toBe("ref={$stored}")
        ->and(trim((string) file_get_contents(storage_path(DOCS_STEPS_DIR.'/git.log'))))
        ->toBe('ls-remote --exit-code https://github.com/beyerenrico/fieldset.git refs/heads/docs-site');
});

it('refuses to deploy a stored commit that is no longer the head of docs-site', function (string $head, int $status, string $reason): void {
    $choose = runChooseCommit(['EVENT' => 'workflow_run', 'MODE' => '', 'STORED' => str_repeat('a', 40)], $head, $status);

    expect($choose->getExitCode())->toBe(1)
        ->and($choose->getOutput())->toContain($reason)
        ->and(chosenRef())->toBe('');
})->with([
    'a later publish' => [str_repeat('b', 40), 0, '::error::docs-site is at '.str_repeat('b', 40).', not at '.str_repeat('a', 40)],
    'the hint' => [str_repeat('b', 40), 0, 'mode=redeploy'],
    'no docs-site branch' => ['', 2, '::error::cannot read the head of docs-site'],
    'an unreachable remote' => ['', 128, '::error::cannot read the head of docs-site'],
]);

it('chooses docs-site for a redeploy and refuses a publish without a stored commit', function (): void {
    $redeploy = runChooseCommit(['EVENT' => 'workflow_dispatch', 'MODE' => 'redeploy', 'STORED' => ''], str_repeat('b', 40));
    $redeployed = chosenRef();
    $gitCalled = File::exists(storage_path(DOCS_STEPS_DIR.'/git.log'));
    $missing = runChooseCommit(['EVENT' => 'workflow_run', 'MODE' => '', 'STORED' => ''], str_repeat('b', 40));

    expect($redeploy->getExitCode())->toBe(0, $redeploy->getOutput())
        ->and($redeployed)->toBe('ref=refs/heads/docs-site')
        ->and($gitCalled)->toBeFalse()
        ->and($missing->getExitCode())->toBe(1)
        ->and($missing->getOutput())->toContain('::error::store reported no stored commit');
});
