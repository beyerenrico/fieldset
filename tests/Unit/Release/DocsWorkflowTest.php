<?php

declare(strict_types=1);

use App\Domain\Instance\Release\ReleaseVersion;
use App\Domain\Instance\Release\SignerIdentity;
use Tests\Support\Release\WorkflowFiles;

/*
 * Guards on .github/workflows/docs.yml (docs site plan U7, KTDs "Publishing and trust") that actionlint does not check:
 * the triggers, the privilege split between the untrusted build and the jobs that verify it, the release proof in
 * resolve, values reaching scripts only through env, no persisted credentials or forced pushes, exact pins and no
 * cache. The store program it runs (docs/site/store/store-artifact.sh) has its behaviour tested in DocsStoreScriptTest,
 * the reconcile warning and deploy's choice of commit in DocsWorkflowStepsTest.
 */

const DOCS_WORKFLOW = '.github/workflows/docs.yml';
const DOCS_STORE_SCRIPT = 'docs/site/store/store-artifact.sh';

function docsWorkflow(): string
{
    return WorkflowFiles::text(DOCS_WORKFLOW);
}

/** @return array<string, string> job id => its block of docs.yml */
function docsJobs(?string $workflow = null): array
{
    return WorkflowFiles::jobs($workflow ?? docsWorkflow());
}

/** @return list<string> a job's permission lines, e.g. "contents: read" */
function docsJobPermissions(string $job): array
{
    preg_match('/^    permissions:\n((?: {6}.*\n)*)/m', $job, $block);

    return array_values(array_filter(array_map(trim(...), explode("\n", $block[1] ?? ''))));
}

/** @return list<string> npm, node, php, composer or docker invocations of a script, comments ignored */
function docsForbiddenTools(string $script): array
{
    $code = (string) preg_replace('/(^|\s)#.*$/m', '$1', $script);
    preg_match_all('/(?<![\w.\/$-])(npm|npx|node|php|composer|docker)(?![\w.-])/', $code, $tools);

    return $tools[1];
}

/** @return list<string> every script store, deploy or resolve runs: its run steps, and store's program */
function docsPrivilegedScripts(string $job, string $block): array
{
    $scripts = WorkflowFiles::runScripts($block);

    return $job === 'store' ? [...$scripts, WorkflowFiles::text(DOCS_STORE_SCRIPT)] : $scripts;
}

/**
 * The workflow-level concurrency group of docs.yml for a run's `github` context. Reads the expression, which must keep
 * the shape `${{ (<dispatch> || (<conditions joined by &&>)) && 'docs-pages' || format('docs-skip-{0}', github.run_id) }}`
 * (null otherwise), and evaluates its `x == y` conditions.
 */
function docsConcurrencyGroup(array $github, ?string $workflow = null): ?string
{
    preg_match("/\nconcurrency:\n  group: >-\n((?:    .*\n)+)  cancel-in-progress: false\n/", $workflow ?? docsWorkflow(), $block);
    $expression = (string) preg_replace('/\s+/', ' ', trim($block[1] ?? ''));
    $shape = <<<'RE'
        /^\$\{\{ \(([^()|&]+) \|\| \(([^()|]+)\)\) && 'docs-pages' \|\| format\('docs-skip-\{0\}', github\.run_id\) \}\}$/
        RE;
    if (preg_match($shape, $expression, $parts) !== 1) {
        return null;
    }
    $value = fn (string $operand): mixed => preg_match("/^'([^']*)'$/", $operand, $literal) === 1 ? $literal[1] : data_get(['github' => $github], $operand);
    $holds = function (string $condition) use ($value): bool {
        $sides = array_map(trim(...), explode(' == ', $condition));

        return count($sides) === 2 && $value($sides[0]) === $value($sides[1]);
    };
    $qualifies = $holds($parts[1]) || array_filter(explode(' && ', $parts[2]), fn (string $condition): bool => ! $holds($condition)) === [];

    return $qualifies ? 'docs-pages' : 'docs-skip-'.$github['run_id'];
}

/** @return array<string, mixed> a workflow_run `github` context of a successful tag push of this repository's release.yml */
function docsReleaseRun(array $run = [], int $runId = 7): array
{
    return [
        'event_name' => 'workflow_run', 'repository' => 'beyerenrico/fieldset', 'run_id' => $runId,
        'event' => ['workflow_run' => [
            'conclusion' => 'success', 'event' => 'push', 'path' => '.github/workflows/release.yml',
            'head_repository' => ['full_name' => 'beyerenrico/fieldset'], ...$run,
        ]],
    ];
}

/** @return list<string> the actions a job block uses, without their pins */
function docsActions(string $job): array
{
    preg_match_all('/uses:\s+([\w.-]+\/[\w.-]+)@/', $job, $actions);

    return $actions[1];
}

it('runs only after a Release run or on dispatch, and never extends release.yml', function (): void {
    $workflow = docsWorkflow();
    $on = substr($workflow, (int) strpos($workflow, "\non:\n") + 5, (int) strpos($workflow, "\npermissions:") - (int) strpos($workflow, "\non:\n") - 5);
    preg_match_all('/^  ([\w-]+):/m', $on, $triggers);

    expect($triggers[1])->toBe(['workflow_run', 'workflow_dispatch'])
        ->and($on)->toContain("  workflow_run:\n    workflows: [Release]\n    types: [completed]\n")
        ->and($on)->toContain("      tag:\n")
        ->and($on)->toContain("        options: [publish, redeploy]\n        default: publish\n")
        ->and($workflow)->not->toMatch('/^\s+(push|pull_request|pull_request_target|schedule|workflow_call):/m')
        ->and($workflow)->not->toContain('uses: ./.github/workflows/');
});

it('grants no default permissions and splits the privileges between the jobs', function (): void {
    $jobs = docsJobs();
    $grants = array_map(docsJobPermissions(...), $jobs);

    expect(docsWorkflow())->toContain("\npermissions: {}\n")
        ->and(array_keys($jobs))->toBe(['resolve', 'build', 'store', 'deploy'])
        ->and($grants)->toBe([
            'resolve' => ['contents: read'],
            'build' => ['contents: read'],
            'store' => ['contents: write'],
            'deploy' => ['contents: read', 'pages: write', 'id-token: write'],
        ]);
    foreach ($jobs as $id => $job) {
        expect(substr_count($job, 'permissions:'))->toBe(1, $id);
    }
});

it('runs store and deploy only on main, in their environments', function (): void {
    $jobs = docsJobs();
    preg_match('/^    if: (.*)$/m', $jobs['store'], $store);

    expect($store[1] ?? '')->toBe("github.ref == 'refs/heads/main' && needs.build.outputs.action == 'publish'")
        ->and($jobs['store'])->toContain("    environment: docs-store\n")
        ->and($jobs['deploy'])->toContain("      !cancelled() && github.ref == 'refs/heads/main'\n")
        ->and($jobs['deploy'])->toContain("    environment:\n      name: github-pages\n")
        ->and($jobs['resolve'])->toContain("(github.event_name == 'workflow_run' && github.ref == 'refs/heads/main'")
        ->and($jobs['resolve'].$jobs['build'])->not->toContain('environment:');
});

it('keeps npm, node, php, composer and docker out of resolve, store and deploy', function (): void {
    foreach (['resolve', 'store', 'deploy'] as $id) {
        $scripts = docsPrivilegedScripts($id, docsJobs()[$id]);
        expect($scripts)->not->toBeEmpty();
        foreach ($scripts as $script) {
            expect(docsForbiddenTools($script))->toBe([], $id);
        }
    }

    expect(docsActions(docsJobs()['resolve']))->toBe(['actions/checkout', 'sigstore/cosign-installer'])
        ->and(docsActions(docsJobs()['store']))->toBe(['actions/checkout', 'actions/download-artifact'])
        ->and(docsActions(docsJobs()['deploy']))->toBe(['actions/checkout', 'actions/upload-pages-artifact', 'actions/deploy-pages'])
        ->and(docsForbiddenTools(implode("\n", WorkflowFiles::runScripts(docsJobs()['build']))))->toContain('npm', 'node', 'docker');
});

it('fails the tool check for a privileged job that runs repository tooling', function (string $job, string $line): void {
    $block = docsJobs()[$job];
    $broken = str_replace($block, $block."      - run: {$line}\n", docsWorkflow());

    expect(docsForbiddenTools(implode("\n", docsPrivilegedScripts($job, docsJobs($broken)[$job]))))->not->toBe([]);
})->with([
    'npm in store' => ['store', 'npm ci'],
    'node in store' => ['store', 'node docs/site/scripts/build.mjs check'],
    'php in resolve' => ['resolve', 'php artisan release:version "$TAG"'],
    'composer in resolve' => ['resolve', 'composer install'],
    'docker in deploy' => ['deploy', 'docker run --rm "$IMAGE"'],
    'npx in deploy' => ['deploy', 'npx vitepress build'],
]);

it('proves the release in resolve before anything builds', function (): void {
    $resolve = docsJobs()['resolve'];
    $verify = (int) strpos($resolve, 'cosign verify-blob');

    foreach ([
        "github.event.workflow_run.event == 'push'",
        "github.event.workflow_run.path == '.github/workflows/release.yml'",
        'github.event.workflow_run.head_repository.full_name == github.repository',
        "github.event.workflow_run.conclusion == 'success'",
        '[ "$RUN_EVENT" = push ]',
        '[ "$RUN_PATH" = .github/workflows/release.yml ]',
        '[ "$RUN_REPOSITORY" = "$GITHUB_REPOSITORY" ]',
        '[ "$RUN_CONCLUSION" = success ]',
        'HEAD_SHA: ${{ github.event.workflow_run.head_sha }}',
        'commit="$(git rev-parse --verify "refs/tags/${TAG}^{commit}")"',
        '[ "$commit" != "$HEAD_SHA" ]',
        'gh release view "$TAG" --repo "$GITHUB_REPOSITORY" --json isDraft',
        '--pattern release-manifest.json --pattern release-manifest.json.sigstore.json',
        '--bundle "$RUNNER_TEMP/release/release-manifest.json.sigstore.json"',
        '--certificate-identity-regexp "$CERTIFICATE_IDENTITY_REGEXP"',
        '--certificate-oidc-issuer "$CERTIFICATE_ISSUER"',
        '--certificate-github-workflow-trigger push',
        '--certificate-github-workflow-ref "refs/tags/$TAG"',
        '--certificate-github-workflow-sha "$COMMIT"',
    ] as $check) {
        expect($resolve)->toContain($check);
    }
    expect($verify)->toBeGreaterThan((int) strpos($resolve, 'gh release download'))
        ->and($verify)->toBeLessThan((int) strpos($resolve, "jq -r '.images.app"))
        ->and(docsJobs()['build'])->toContain('needs: resolve')
        ->and(docsJobs()['build'])->toContain('IMAGE: ${{ needs.resolve.outputs.image }}');
});

it('verifies against the signer install.sh trusts, with the pinned cosign release', function (string $name): void {
    preg_match("/^  {$name}: '?(.+?)'?$/m", docsWorkflow(), $docs);
    preg_match("/^  {$name}: '?(.+?)'?$/m", WorkflowFiles::text('.github/workflows/release.yml'), $release);

    expect($docs[1] ?? null)->not->toBeNull()
        ->and($docs[1] ?? null)->toBe($release[1] ?? '');
})->with(['CERTIFICATE_IDENTITY_REGEXP', 'CERTIFICATE_ISSUER', 'COSIGN_RELEASE']);

it('uses the production signer identity and the release tag pattern', function (): void {
    preg_match("/^  CERTIFICATE_IDENTITY_REGEXP: '(.+)'$/m", docsWorkflow(), $identity);

    expect($identity[1] ?? '')->toBe(SignerIdentity::production()->identityRegexp)
        ->and(docsWorkflow())->toContain("  RELEASE_TAG_PATTERN: '^v".substr(str_replace('(?:', '(', ReleaseVersion::PATTERN), 1)."'\n");
});

it('checks every tag against the release tag pattern before git or an action uses it', function (): void {
    $jobs = docsJobs();
    $resolve = $jobs['resolve'];

    expect((int) strpos($resolve, '[[ "$tag" =~ $RELEASE_TAG_PATTERN ]]'))->toBeLessThan((int) strpos($resolve, 'echo "tag=$tag"'))
        ->and((int) strpos($resolve, 'echo "tag=$tag"'))->toBeLessThan((int) strpos($resolve, 'ref: refs/tags/${{ steps.tag.outputs.tag }}'));
    foreach (['build' => 'git fetch --no-tags --depth=1 origin "+refs/tags/$TAG', 'store' => 'git -C tag fetch'] as $id => $use) {
        $check = (int) strpos($jobs[$id], '"$TAG" =~ $RELEASE_TAG_PATTERN');
        expect($check)->toBeGreaterThan(0, $id)
            ->and($check)->toBeLessThan((int) strpos($jobs[$id], $use), $id);
    }
});

it('passes inputs, event values and job outputs to scripts only through env', function (): void {
    foreach (docsJobs() as $id => $job) {
        $scripts = WorkflowFiles::runScripts($job);
        expect($scripts)->toHaveCount(WorkflowFiles::runCount($job), $id);
        foreach ($scripts as $script) {
            expect(str_contains($script, '${{'))->toBeFalse($id); // not->toContain() would read $id as a second needle
        }
    }

    $broken = str_replace('bash tooling/docs/site/store/store-artifact.sh', 'bash tooling/docs/site/store/store-artifact.sh --tag ${{ needs.resolve.outputs.tag }}', docsWorkflow());
    expect(implode("\n", WorkflowFiles::runScripts(docsJobs($broken)['store'])))->toContain('${{ needs.');
});

it('keeps no credentials in a checkout and lets only the push step of store hold the token', function (): void {
    $jobs = docsJobs();
    $steps = WorkflowFiles::steps($jobs['store']);
    $push = end($steps);

    foreach ($jobs as $id => $job) {
        expect(substr_count($job, 'uses: actions/checkout@'))->toBe(substr_count($job, "persist-credentials: false\n"), $id);
    }
    expect(preg_match_all('/secrets\.|github\.token/', $jobs['store']))->toBe(1)
        ->and($push)->toContain('TOKEN: ${{ secrets.GITHUB_TOKEN }}')
        ->and($push)->toContain('git -C site push "https://x-access-token:${TOKEN}@github.com/${GITHUB_REPOSITORY}.git" HEAD:refs/heads/docs-site')
        ->and($jobs['deploy'])->not->toMatch('/secrets\.|github\.token/');
});

it('pushes once, to docs-site, as a fast-forward that is never forced', function (): void {
    $scripts = [...array_merge(...array_values(array_map(WorkflowFiles::runScripts(...), docsJobs()))), WorkflowFiles::text(DOCS_STORE_SCRIPT)];
    $code = (string) preg_replace('/(^|\s)#.*$/m', '$1', implode("\n", $scripts));
    preg_match_all('/^.*\bgit\b.*\bpush\b.*$/m', $code, $pushes);

    expect(array_map(trim(...), $pushes[0]))
        ->toBe(['git -C site push "https://x-access-token:${TOKEN}@github.com/${GITHUB_REPOSITORY}.git" HEAD:refs/heads/docs-site']);
});

it('gives build a token only in its reconcile step, before any untrusted code runs', function (): void {
    $build = docsJobs()['build'];
    $reconcile = (int) strpos($build, 'gh release list');
    $export = array_values(array_filter(WorkflowFiles::steps($build), fn (string $step): bool => str_contains($step, 'docker run')))[0] ?? '';

    expect(preg_match_all('/secrets\.|github\.token/', $build))->toBe(1)
        ->and($reconcile)->toBeGreaterThan(0)
        ->and($reconcile)->toBeLessThan((int) strpos($build, 'docker pull'))
        ->and($reconcile)->toBeLessThan((int) strpos($build, 'npm ci'))
        ->and($build)->toContain('::warning::')
        ->and($export)->toContain('docker run --rm --pull never --network host')
        ->and($export)->toContain('-e CACHE_STORE=array -e SESSION_DRIVER=array -e QUEUE_CONNECTION=sync')
        ->and($export)->not->toMatch('/TOKEN|secrets\./')
        ->and($build)->toContain('--built-with "$GITHUB_SHA"')
        ->and($build)->toContain('ref: ${{ github.sha }}');
});

it('pins every action by SHA, uses no cache and serializes publishes without cancelling', function (): void {
    $workflow = docsWorkflow();
    $pins = WorkflowFiles::actionPins(DOCS_WORKFLOW, $workflow);

    expect($pins['unpinned'])->toBe([])
        ->and($pins['checked'])->toBe(10)
        ->and($workflow)->not->toContain('actions/cache')
        ->and($workflow)->not->toMatch('/^\s+cache(-dependency-path)?:/m')
        ->and($workflow)->toContain("          package-manager-cache: false\n")
        ->and($workflow)->toContain("\n  cancel-in-progress: false\n")
        ->and($workflow)->not->toContain('cancel-in-progress: true');
});

it('serializes the runs that can publish in docs-pages and gives every other run a group of its own', function (array $github, string $group): void {
    expect(docsConcurrencyGroup($github))->toBe($group);
})->with([
    'a dispatch' => [['event_name' => 'workflow_dispatch', 'repository' => 'beyerenrico/fieldset', 'run_id' => 7], 'docs-pages'],
    'a successful Release run' => [docsReleaseRun(), 'docs-pages'],
    'a failed Release run' => [docsReleaseRun(['conclusion' => 'failure']), 'docs-skip-7'],
    'a cancelled Release run' => [docsReleaseRun(['conclusion' => 'cancelled']), 'docs-skip-7'],
    'a Release run of another repository' => [docsReleaseRun(['head_repository' => ['full_name' => 'evil/fieldset']]), 'docs-skip-7'],
    'a Release run not started by a push' => [docsReleaseRun(['event' => 'workflow_dispatch']), 'docs-skip-7'],
    'a run of another workflow called Release' => [docsReleaseRun(['path' => '.github/workflows/evil.yml']), 'docs-skip-7'],
]);

it('keeps the docs-pages conditions those of resolve and the other groups unique per run', function (): void {
    preg_match('/^    if: >-\n((?: {6}.*\n)+)/m', docsJobs()['resolve'], $if);
    preg_match_all("/github\.event\.workflow_run\.[\w.]+ == (?:'[^']*'|github\.repository)/", $if[1] ?? '', $resolve);
    preg_match("/\nconcurrency:\n  group: >-\n((?:    .*\n)+)/", docsWorkflow(), $group);
    preg_match_all("/github\.event\.workflow_run\.[\w.]+ == (?:'[^']*'|github\.repository)/", $group[1] ?? '', $concurrency);
    $broken = str_replace("'docs-skip-{0}', github.run_id", "'docs-skip-{0}', github.workflow", docsWorkflow());

    expect($resolve[0])->toHaveCount(4)
        ->and($concurrency[0])->toEqualCanonicalizing($resolve[0])
        ->and(docsConcurrencyGroup(docsReleaseRun(['conclusion' => 'failure'], 8)))->not->toBe(docsConcurrencyGroup(docsReleaseRun(['conclusion' => 'failure'], 9)))
        ->and(docsConcurrencyGroup(docsReleaseRun(), $broken))->toBeNull();
});

it('fails the pin check for an action in docs.yml that is not pinned by SHA with its version', function (string $ref): void {
    $workflow = docsWorkflow();
    preg_match('/uses: ([\w.-]+\/[\w.-]+)@\S+(?: # \S+)?$/m', $workflow, $use);
    $broken = str_replace($use[0], "uses: {$use[1]}@{$ref}", $workflow);

    expect(WorkflowFiles::actionPins(DOCS_WORKFLOW, $broken)['unpinned'])->toHaveCount(substr_count($workflow, $use[0]));
})->with([
    'a major tag' => 'v7',
    'a branch' => 'main',
    'a SHA without its version' => str_repeat('a', 40),
]);

it('stores with the program from main, which checks the reference CSP, and pushes without force', function (): void {
    $store = docsJobs()['store'];
    $script = WorkflowFiles::text(DOCS_STORE_SCRIPT);
    preg_match('/export const REFERENCE_CSP = (.+?);\n/s', WorkflowFiles::text('docs/site/lib/openapi.mjs'), $csp);
    preg_match_all('/"([^"]*)"/', $csp[1] ?? '', $parts); // "…" + "…": the literal's concatenated parts
    $literal = implode('', $parts[1]);
    preg_match('/^readonly REFERENCE_CSP="(.+)" #/m', $script, $stored);

    expect($store)->toContain("          ref: \${{ github.sha }}\n          path: tooling\n          persist-credentials: false\n          sparse-checkout: docs/site/store\n")
        ->and($store)->toContain('bash tooling/docs/site/store/store-artifact.sh --artifact "$RUNNER_TEMP/artifact"')
        ->and($store)->toContain('--tag "$TAG" --commit "$COMMIT" --minor "$MINOR" --tooling "$GITHUB_SHA"')
        ->and($store)->toContain('MINOR: ${{ needs.resolve.outputs.minor }}')
        ->and(substr_count($store, 'needs.build.outputs.'))->toBe(1) // store takes nothing from build but its gate (the if)
        ->and($script)->toContain('ORDER_JQ="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/version-order.jq"')
        ->and($script)->toContain('find "$ART" -type f -name api-reference.html -print0')
        ->and($literal)->toStartWith("default-src 'self';")
        ->and($stored[1] ?? null)->toBe($literal);
});

it('names the ownership classes and repository of build.mjs in the store program', function (): void {
    $build = WorkflowFiles::text('docs/site/scripts/build.mjs');
    $script = WorkflowFiles::text(DOCS_STORE_SCRIPT);
    preg_match('/export const ROOT_OWNED = Object\.freeze\(\[(.*?)\]\);/s', $build, $owned);
    preg_match_all("/'([^']+)'/", $owned[1] ?? '', $entries);
    preg_match("/export const REPOSITORY = '([^']+)';/", $build, $repository);

    expect($entries[1])->not->toBeEmpty()
        ->and($script)->toContain('readonly ROOT_OWNED=('.implode(' ', $entries[1]).') #')
        ->and($script)->toContain("readonly REPOSITORY='".($repository[1] ?? '')."' #");
});

it('deploys only a stored commit without symlinks or submodules', function (): void {
    $deploy = docsJobs()['deploy'];
    $check = (int) strpos($deploy, "grep -E '^(120000|160000) '");

    expect($deploy)->toContain('STORED: ${{ needs.store.outputs.commit }}')
        ->and($deploy)->toContain('echo "ref=refs/heads/docs-site"')
        ->and($deploy)->toContain('ref: ${{ steps.commit.outputs.ref }}')
        ->and($check)->toBeGreaterThan(0)
        ->and($check)->toBeLessThan((int) strpos($deploy, 'actions/upload-pages-artifact@'))
        ->and($deploy)->toContain('rm -rf site/.git')
        ->and($deploy)->toContain("        with:\n          path: site\n");
});

it('deploys a stored commit only while it is the head of docs-site', function (): void {
    $choose = WorkflowFiles::runScripts(docsJobs()['deploy'])[0];
    $head = (int) strpos($choose, 'git ls-remote --exit-code "$GITHUB_SERVER_URL/$GITHUB_REPOSITORY.git" refs/heads/docs-site | cut -f1');
    $compare = (int) strpos($choose, 'if [ "$head" != "$STORED" ]; then');

    expect($head)->toBeGreaterThan(0)
        ->and($compare)->toBeGreaterThan($head)
        ->and($compare)->toBeLessThan((int) strpos($choose, 'echo "ref=$STORED" >> "$GITHUB_OUTPUT"'))
        ->and(substr($choose, $compare, 400))->toContain('mode=redeploy');
});
