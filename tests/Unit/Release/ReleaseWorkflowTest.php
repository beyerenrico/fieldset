<?php

declare(strict_types=1);

use App\Domain\Instance\Release\ReleaseVersion;
use App\Domain\Instance\Release\SignerIdentity;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;
use Tests\Support\Release\WorkflowFiles;

/*
 * Guards on .github/workflows/release.yml (KTD22, ADR 0060, ADR 0067) that actionlint does not check: the tag
 * trigger, no default token permissions, actions pinned by full commit SHA with a version comment, images pinned
 * by digest, and the signer identity install.sh will build in (U4). Also the docs job of ci.yml (docs site plan U6):
 * pinned actions, a read-only token, workflow values only through env; and no workflow runs on pull_request_target.
 * docs.yml has its own guards (DocsWorkflowTest); release.yml stays uncallable, so docs.yml can never extend it (U7).
 */

afterEach(function (): void {
    File::deleteDirectory(storage_path('framework/testing/verify-public'));
});

function releaseWorkflow(): string
{
    return WorkflowFiles::text('.github/workflows/release.yml');
}

function signerIdentityPattern(): string
{
    preg_match("/^  CERTIFICATE_IDENTITY_REGEXP: '(.+)'$/m", releaseWorkflow(), $match);

    return $match[1] ?? '';
}

it('runs only for pushed v* tags, with no default token permissions', function (): void {
    expect(releaseWorkflow())->toContain("on:\n  push:\n    tags: ['v*']\n")
        ->and(releaseWorkflow())->toContain("\npermissions: {}\n")
        ->and(releaseWorkflow())->not->toContain('pull_request')
        ->and(releaseWorkflow())->not->toContain('workflow_dispatch');
});

it('cannot be called by another workflow, so no workflow can borrow its signer identity (docs site plan U7)', function (): void {
    expect(releaseWorkflow())->not->toContain('workflow_call');
});

it('pins every action by full commit SHA with a version comment', function (string $workflow, int $pinned): void {
    $text = WorkflowFiles::text($workflow);
    $pins = WorkflowFiles::actionPins($workflow, $text);
    preg_match_all('/^\s+(?:-\s+)?uses:\s/m', $text, $uses);

    expect($pins['unpinned'])->toBe([])
        ->and($pins['checked'])->toBeGreaterThanOrEqual($pinned)
        ->and(array_sum(array_map(fn (string $job): int => preg_match_all('/^\s+(?:-\s+)?uses:\s/m', $job), WorkflowFiles::jobs($text))))
        ->toBe(count($uses[0])); // every use sits in a job block
})->with([
    'release' => ['.github/workflows/release.yml', 6],
    // The install-journeys job and its five pinned actions moved to their own workflow (ADR 0091 amendment 2026-10-22).
    // The 2.0 jobs check out by SHA; the docs job pins checkout, setup-php and setup-node (docs site plan U6).
    'ci' => ['.github/workflows/ci.yml', 7],
    'install journeys' => ['.github/workflows/install-journeys.yml', 5],
    // resolve 2, build 3, store 2, deploy 3 (docs site plan U7).
    'docs' => ['.github/workflows/docs.yml', 10],
]);

it('fails the pin check for an action in the docs job that is not pinned by SHA with its version', function (string $ref): void {
    $ci = WorkflowFiles::text('.github/workflows/ci.yml');
    $docs = WorkflowFiles::jobs($ci)['docs'];
    preg_match('/uses: ([\w.-]+\/[\w.-]+)@\S+(?: # \S+)?$/m', $docs, $use); // the docs job's first action
    $broken = str_replace($docs, str_replace($use[0], "uses: {$use[1]}@{$ref}", $docs), $ci);

    expect(WorkflowFiles::LEGACY_CI_JOBS)->not->toContain('docs')
        ->and(WorkflowFiles::actionPins('.github/workflows/ci.yml', $broken)['unpinned'])->toBe(["docs: - uses: {$use[1]}@{$ref}"]);
})->with([
    'a major tag' => 'v4',
    'a branch' => 'main',
    'a short SHA' => '3d3c42e5 # v7.0.1',
    'a SHA without its version' => str_repeat('a', 40),
]);

it('gives the docs job a read-only token that its checkout does not keep, and no secrets', function (): void {
    $docs = WorkflowFiles::jobs(WorkflowFiles::text('.github/workflows/ci.yml'))['docs'];
    preg_match('/^    permissions:\n((?: {6}.*\n)*)/m', $docs, $permissions);

    expect($permissions[1] ?? null)->toBe("      contents: read\n")
        ->and(substr_count($docs, 'permissions:'))->toBe(1)
        ->and($docs)->toContain("persist-credentials: false\n")
        ->and($docs)->not->toContain('secrets.')
        ->and($docs)->not->toContain('actions/cache');
});

it('bounds the docs job with a timeout', function (): void {
    expect(WorkflowFiles::jobs(WorkflowFiles::text('.github/workflows/ci.yml'))['docs'])->toMatch('/^    timeout-minutes: [1-9]\d*$/m');
});

it('passes workflow values to the docs job\'s scripts only through env, and never runs the root build', function (): void {
    $docs = WorkflowFiles::jobs(WorkflowFiles::text('.github/workflows/ci.yml'))['docs'];
    $scripts = WorkflowFiles::runScripts($docs);

    expect($scripts)->toHaveCount(preg_match_all('/^ +(?:- )?run: /m', $docs))
        ->and(implode("\n", $scripts))->toContain('node docs/site/scripts/build.mjs check --source "$src" --tag "$tag"');
    foreach ($scripts as $script) {
        expect($script)->not->toContain('${{')
            ->and($script)->not->toContain('npm run build')
            ->and($script)->not->toContain('--stub-export');
    }
});

it('checks every tag docs-site lists against the release tag pattern before git uses it', function (): void {
    $docs = WorkflowFiles::jobs(WorkflowFiles::text('.github/workflows/ci.yml'))['docs'];
    $pattern = (int) strpos($docs, 'if ! [[ "$tag" =~ $pattern ]]; then');

    expect($docs)->toContain('git ls-remote --exit-code origin refs/heads/docs-site')
        ->and($docs)->toContain("pattern='^v".substr(str_replace('(?:', '(', ReleaseVersion::PATTERN), 1)."'")
        ->and($pattern)->toBeGreaterThan(0)
        ->and($pattern)->toBeLessThan((int) strpos($docs, 'refs/tags/$tag'));
});

it('runs no workflow on pull_request_target', function (): void {
    $workflows = glob(base_path('.github/workflows/*.y*ml')) ?: [];

    expect($workflows)->not->toBeEmpty();
    foreach ($workflows as $workflow) {
        // not->toContain() would take basename() as a second needle and pass whenever either is missing.
        expect(str_contains((string) file_get_contents($workflow), 'pull_request_target'))->toBeFalse(basename($workflow));
    }
});

it('pins every container image it runs by digest', function (string $workflow): void {
    preg_match_all('/docker\.io\/\S+|ghcr\.io\/sigstore\S+/', (string) file_get_contents(base_path($workflow)), $images);

    expect($images[0])->not->toBeEmpty();
    foreach ($images[0] as $image) {
        expect($image)->toMatch('/:[\w.-]+@sha256:[a-f0-9]{64}$/');
    }
})->with(['.github/workflows/release.yml', '.github/workflows/ci.yml', '.github/workflows/docs.yml']);

it('pins the signer identity to this workflow on a release tag', function (): void {
    $pattern = '/'.str_replace('/', '\/', signerIdentityPattern()).'/';
    $identity = fn (string $ref): string => "https://github.com/beyerenrico/fieldset/.github/workflows/release.yml@{$ref}";

    foreach (['v2.0.0', 'v2.0.0-alpha.1', 'v2.0.0-rc.1', 'v10.2.33', 'v2.0.0-beta.1.2'] as $tag) {
        expect(preg_match($pattern, $identity("refs/tags/{$tag}")))->toBe(1, $tag)
            ->and(ReleaseVersion::fromTag($tag)->tag())->toBe($tag);
    }

    foreach ([
        $identity('refs/heads/main'),
        $identity('refs/tags/v2.0'),
        $identity('refs/tags/v2.0.0-dev.1'),
        $identity('refs/tags/v2.0.0+build'),
        $identity('refs/tags/v2.0.0/x'),
        'https://github.com/beyerenrico/fieldset/.github/workflows/ci.yml@refs/tags/v2.0.0',
        'https://github.com/someone-else/fieldset/.github/workflows/release.yml@refs/tags/v2.0.0',
        'https://github.com/beyerenrico/fieldset/.github/workflows/release.yml@refs/tags/v2.0.0 ',
        'xhttps://github.com/beyerenrico/fieldset/.github/workflows/release.yml@refs/tags/v2.0.0',
    ] as $other) {
        expect(preg_match($pattern, $other))->toBe(0, $other);
    }

    expect(releaseWorkflow())->toContain('CERTIFICATE_ISSUER: https://token.actions.githubusercontent.com');
});

it('uses the signer identity built into the image and install.sh (U4)', function (): void {
    expect(signerIdentityPattern())->toBe(SignerIdentity::production()->identityRegexp)
        ->and(releaseWorkflow())->toContain('CERTIFICATE_ISSUER: '.SignerIdentity::GITHUB_ACTIONS_ISSUER);
});

/** @return array<string, string> job id => its block of release.yml */
function releaseJobs(): array
{
    return WorkflowFiles::jobs(releaseWorkflow());
}

it('runs no PHP, Composer or repository code in a job that can mint an OIDC token', function (): void {
    $jobs = releaseJobs();
    $signing = array_keys(array_filter($jobs, fn (string $job): bool => str_contains($job, 'id-token: write')));

    expect($signing)->toBe(['image', 'sign'])
        ->and($jobs['manifest'])->not->toContain('id-token')
        ->and($jobs['manifest'])->toContain('php artisan release:manifest')
        ->and($jobs['manifest'])->toContain('composer install --no-dev --no-scripts --no-plugins ')
        ->and($jobs['prepare'])->toContain('composer install --no-dev --no-scripts --no-plugins ');
    foreach (['composer', 'php ', 'setup-php', 'artisan', 'npm ', 'make '] as $tool) {
        expect($jobs['sign'])->not->toContain($tool);
    }
    preg_match_all('/uses:\s+(\S+)@/', $jobs['sign'], $actions);
    expect($actions[1])->toBe(['actions/checkout', 'actions/download-artifact', 'sigstore/cosign-installer', 'actions/upload-artifact'])
        ->and($jobs['sign'])->toContain('sparse-checkout');
});

it('signs only after checking the manifest against the pushed image digest and the tagged files', function (): void {
    $sign = releaseJobs()['sign'];
    $check = (int) strpos($sign, 'Check the manifest against the pushed image');

    expect($check)->toBeGreaterThan(0)
        ->and($check)->toBeLessThan((int) strpos($sign, 'cosign sign-blob'))
        ->and($sign)->toContain('DIGEST: ${{ needs.image.outputs.digest }}')
        ->and($sign)->toContain('.images.app.digest')
        ->and($sign)->toContain('cmp -s "$file" "tagged/${tagged[$name]}"')
        ->and($sign)->toContain('does not match its checksum in the manifest')
        ->and($sign)->toContain('name: release-unsigned')
        ->and($sign)->toContain('name: release-assets')
        ->and(releaseJobs()['scan'])->toContain('needs: sign');
});

it('publishes the checked-in production install.sh unchanged, never a harness build (ADR 0070)', function (): void {
    expect(releaseWorkflow())->toContain('install.sh is not the production build')
        ->and(releaseWorkflow())->toContain('cp "${assets[@]}" distribution/manifest.schema.json dist/')
        ->and(releaseWorkflow())->not->toMatch('/sed[^\n]*install\.sh/')
        ->and(releaseWorkflow())->not->toContain('FIELDSET_BUILD');
});

it('renders the stack file from the manifest pins and signs only after checking its images (R18, ADR 0076)', function (): void {
    $jobs = releaseJobs();

    expect($jobs['manifest'])->toContain('--stack-file=dist/compose.stack.yaml')
        ->and($jobs['manifest'])->not->toContain('distribution/stack/compose.stack.yaml')
        ->and($jobs['sign'])->toContain('for required in install.sh shim.sh compose.stack.yaml; do')
        ->and($jobs['sign'])->toContain('which the manifest does not pin')
        ->and($jobs['sign'])->toContain('compose.stack.yaml mounts the Docker socket')
        ->and((int) strpos($jobs['sign'], 'which the manifest does not pin'))->toBeLessThan((int) strpos($jobs['sign'], 'cosign sign-blob'));
});

it('stops before the unsigned artifact when the app image\'s pg_dump is older than the manifest\'s Postgres (R39, ADR 0091)', function (): void {
    $manifest = releaseJobs()['manifest'];
    $check = (int) strpos($manifest, 'php artisan release:check-pg-dump dist/release-manifest.json');

    expect($check)->toBeGreaterThan((int) strpos($manifest, 'php artisan release:manifest'))
        ->and($check)->toBeLessThan((int) strpos($manifest, 'name: release-unsigned'))
        ->and($manifest)->toContain('docker/setup-qemu-action@')
        ->and($manifest)->toContain('packages: read')
        ->and($manifest)->not->toContain('packages: write');
});

/**
 * Runs verify-public's pull loop against a manifest, with a docker stub that behaves like the classic image store: a
 * digest reference holds one image, so pulling the same digest for a second platform fails until it is removed
 * ("cannot overwrite digest", release run 39769444878).
 *
 * @param  list<string>  $images  repository names; one containing "missing" is refused by the registry
 */
function runVerifyPublic(array $images): Process
{
    preg_match('/run: \|\n(.*)\z/s', releaseJobs()['verify-public'], $run);
    $dir = storage_path('framework/testing/verify-public');
    File::deleteDirectory($dir);
    File::ensureDirectoryExists("{$dir}/bin");
    File::ensureDirectoryExists("{$dir}/dist");
    File::put("{$dir}/pull.sh", (string) preg_replace('/^ {10}/m', '', $run[1] ?? ''));
    File::put("{$dir}/dist/release-manifest.json", (string) json_encode([
        'platforms' => ['linux/amd64', 'linux/arm64'],
        'images' => array_map(fn (string $name): array => ['registry' => 'docker.io', 'repository' => $name, 'digest' => 'sha256:'.str_repeat('a', 64)], $images),
    ]));
    File::put("{$dir}/bin/docker", <<<'SH'
        #!/usr/bin/env bash
        ref="${*: -1}"
        touch store
        case "$1 $2" in
          pull*) [[ "$ref" == *missing* ]] && exit 1
                 grep -qxF "$ref" store && { echo "cannot overwrite digest" >&2; exit 1; }
                 echo "$ref" >> store; echo "$*" >> pulls ;;
          "image rm") grep -vxF "$ref" store > store.new || true; mv store.new store ;;
          *) exit 2 ;;
        esac
        SH);
    chmod("{$dir}/bin/docker", 0755);

    return tap(new Process(['bash', '-e', '-o', 'pipefail', 'pull.sh'], $dir, ['PATH' => "{$dir}/bin:".getenv('PATH')]))->run();
}

it('pulls every manifest digest on every platform, removing each image before the next platform', function (): void {
    $pull = runVerifyPublic(['library/postgres', 'valkey/valkey']);

    expect($pull->getExitCode())->toBe(0, $pull->getErrorOutput())
        ->and(substr_count((string) file_get_contents(storage_path('framework/testing/verify-public/pulls')), "\n"))->toBe(4);
});

it('reports every image that cannot be pulled anonymously and fails after trying the rest', function (): void {
    $pull = runVerifyPublic(['library/missing', 'valkey/valkey']);

    expect($pull->getExitCode())->toBe(1)
        ->and($pull->getOutput())->toContain('::error::anonymous pull failed: docker.io/library/missing@sha256:')
        ->and(substr_count((string) file_get_contents(storage_path('framework/testing/verify-public/pulls')), 'valkey/valkey@'))->toBe(2);
});
