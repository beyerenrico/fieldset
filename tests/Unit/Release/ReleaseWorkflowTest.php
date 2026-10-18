<?php

declare(strict_types=1);

use App\Domain\Instance\Release\ReleaseVersion;
use App\Domain\Instance\Release\SignerIdentity;

/*
 * Guards on .github/workflows/release.yml (KTD22, ADR 0060, ADR 0067) that actionlint does not check: the tag
 * trigger, no default token permissions, actions pinned by full commit SHA with a version comment, images pinned
 * by digest, and the signer identity install.sh will build in (U4).
 */

function releaseWorkflow(): string
{
    return (string) file_get_contents(base_path('.github/workflows/release.yml'));
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

it('pins every action by full commit SHA with a version comment', function (string $workflow): void {
    preg_match_all('/^\s+(?:-\s+)?uses:\s+(\S+)(.*)$/m', (string) file_get_contents(base_path($workflow)), $uses, PREG_SET_ORDER);
    $release = $workflow === '.github/workflows/release.yml';
    $checked = 0;

    foreach ($uses as [$line, $action, $comment]) {
        if (! $release && preg_match('/@v\d+$/', $action) === 1) {
            continue; // jobs from before 2.0 keep their tags (plan U7: existing CI jobs unchanged)
        }

        $checked++;
        expect($action)->toMatch('/^[\w.-]+\/[\w.-]+@[0-9a-f]{40}$/', $line)
            ->and($comment)->toMatch('/^ # v?\d+\.\d+\.\d+$/', $line);
    }

    expect($checked)->toBeGreaterThan(5);
})->with(['.github/workflows/release.yml', '.github/workflows/ci.yml']);

it('pins every container image it runs by digest', function (string $workflow): void {
    preg_match_all('/docker\.io\/\S+|ghcr\.io\/sigstore\S+/', (string) file_get_contents(base_path($workflow)), $images);

    expect($images[0])->not->toBeEmpty();
    foreach ($images[0] as $image) {
        expect($image)->toMatch('/:[\w.-]+@sha256:[a-f0-9]{64}$/');
    }
})->with(['.github/workflows/release.yml', '.github/workflows/ci.yml']);

it('pins the signer identity to this workflow on a release tag', function (): void {
    $pattern = '/'.str_replace('/', '\/', signerIdentityPattern()).'/';
    $identity = fn (string $ref): string => "https://github.com/beyerenrico/form-schema-app/.github/workflows/release.yml@{$ref}";

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
        'https://github.com/beyerenrico/form-schema-app/.github/workflows/ci.yml@refs/tags/v2.0.0',
        'https://github.com/someone-else/form-schema-app/.github/workflows/release.yml@refs/tags/v2.0.0',
        'https://github.com/beyerenrico/form-schema-app/.github/workflows/release.yml@refs/tags/v2.0.0 ',
        'xhttps://github.com/beyerenrico/form-schema-app/.github/workflows/release.yml@refs/tags/v2.0.0',
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
    $jobs = substr(releaseWorkflow(), (int) strpos(releaseWorkflow(), "\njobs:\n") + 7);
    preg_match_all('/^  ([a-z][a-z0-9-]*):\n(.*?)(?=^  [a-z][a-z0-9-]*:\n|\z)/ms', $jobs, $matches, PREG_SET_ORDER);

    return array_column($matches, 2, 1);
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
        ->and($sign)->toContain(".images.app.digest")
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
        ->and(releaseWorkflow())->not->toContain('FSA_BUILD');
});
