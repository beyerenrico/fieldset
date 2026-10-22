<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;
use Tests\Support\Release\DocsStoreFixture;

/*
 * docs/site/store/store-artifact.sh, the program the privileged store job of docs.yml runs on the untrusted build
 * artifact (docs site plan U7, KTD "Jobs split by privilege; privileged jobs verify the untrusted build"): what it
 * accepts and commits, and that every tampered artifact is rejected before anything is committed.
 */

beforeEach(function (): void {
    $this->fixture = new DocsStoreFixture(storage_path('framework/testing/docs-store-'.getmypid()));
});

afterEach(function (): void {
    File::deleteDirectory(storage_path('framework/testing/docs-store-'.getmypid())); // also after a failed setup
});

/** A docs-site state in which 2.2.0 is the newest final and 2.1 holds the rc.1 copy, so 2.1 does not refresh the root. */
function docsStoreWithNewerMinor(DocsStoreFixture $fixture, string $stored21 = DocsStoreFixture::TAG): array
{
    $other = DocsStoreFixture::copy('v2.2.0', str_repeat('c', 40));
    $fixture->storeState(
        ['schema' => 1, 'latest' => '2.2', 'copies' => [$other, DocsStoreFixture::copy($stored21, str_repeat('d', 40))]],
        ['2.2/index.html' => "2.2\n", 'index.html' => "root of 2.2\n", 'spec/form-definition/1.0/meta-schema.json' => "{}\n"],
    );
    $versions = ['schema' => 1, 'latest' => '2.2', 'copies' => [$other, DocsStoreFixture::copy(DocsStoreFixture::TAG)]];
    $fixture->withoutRoot($versions);

    return $versions;
}

it('commits a first publish with the copy, versions.json, the root and the spec', function (): void {
    $store = $this->fixture->store();
    $files = explode("\n", $this->fixture->git('site', 'ls-tree', '-r', '--name-only', 'HEAD'));

    expect($store->getExitCode())->toBe(0, $store->getErrorOutput())
        ->and($store->getOutput())->toContain('root refreshed: true')
        ->and($files)->toContain('2.1/api-reference.html', 'versions.json', 'index.html', 'latest/index.html', 'spec/form-definition/1.0/meta-schema.json')
        ->and($this->fixture->git('site', 'log', '-1', '--format=%an <%ae>|%s'))
        ->toBe('github-actions[bot] <41898282+github-actions[bot]@users.noreply.github.com>|docs: publish v2.1.0-rc.1 as /fieldset/2.1/');
});

it('commits nothing when a re-run stores the same files', function (): void {
    expect($this->fixture->store()->getExitCode())->toBe(0);
    $head = $this->fixture->git('site', 'rev-parse', 'HEAD');
    $rerun = $this->fixture->store();

    expect($rerun->getExitCode())->toBe(0, $rerun->getErrorOutput())
        ->and($rerun->getOutput())->toContain('stored unchanged')
        ->and($this->fixture->git('site', 'rev-parse', 'HEAD'))->toBe($head);
});

it('changes only the copy and versions.json when the published minor is not latest', function (): void {
    docsStoreWithNewerMinor($this->fixture);
    $base = $this->fixture->git('site', 'rev-parse', 'HEAD');
    $store = $this->fixture->store();
    $changed = explode("\n", $this->fixture->git('site', 'diff', '--name-only', $base, 'HEAD'));

    expect($store->getExitCode())->toBe(0, $store->getErrorOutput())
        ->and($store->getOutput())->toContain('latest 2.2, root refreshed: false')
        ->and(array_values(array_unique(array_map(fn (string $path): string => explode('/', $path)[0], $changed))))->toBe(['2.1', 'versions.json'])
        ->and($this->fixture->git('site', 'show', 'HEAD:index.html'))->toBe('root of 2.2');
});

it('rejects a tampered first publish and commits nothing', function (Closure $tamper, string $reason): void {
    $tamper($this->fixture);
    $store = $this->fixture->store();

    expect($store->getExitCode())->toBe(1, $store->getOutput())
        ->and($store->getErrorOutput())->toContain($reason)
        ->and(new Process(['git', '-C', $this->fixture->dir.'/site', 'rev-parse', '--verify', '-q', 'HEAD'])->run())->toBe(1);
})->with([
    'an extra top-level entry' => [fn (DocsStoreFixture $f) => $f->put('artifact/site/dev/index.html', 'x'), 'the artifact holds dev, which no ownership class'],
    'a changed spec JSON file' => [fn (DocsStoreFixture $f) => $f->put('artifact/site/spec/form-definition/1.0/meta-schema.json', '{}'), 'meta-schema.json differs from'],
    'an extra spec JSON file' => [fn (DocsStoreFixture $f) => $f->put('artifact/site/spec/form-definition/1.0/evil.json', '{}'), 'the spec JSON files differ'],
    'a foreign install URL' => [fn (DocsStoreFixture $f) => $f->edit('artifact/site/2.1/install.html', DocsStoreFixture::LATEST_INSTALL, 'https://evil.example/install.sh'), 'install.sh URL that v2.1.0-rc.1 does not'],
    'another release\'s install URL' => [fn (DocsStoreFixture $f) => $f->edit('artifact/site/index.html', 'download/v2.1.0-rc.1/', 'download/v9.9.9/'), 'install.sh URL that v2.1.0-rc.1 does not'],
    'a reference page without the CSP' => [fn (DocsStoreFixture $f) => $f->edit('artifact/site/2.1/api-reference.html', DocsStoreFixture::CSP_META, ''), 'exactly one Content-Security-Policy'],
    'a weakened CSP' => [fn (DocsStoreFixture $f) => $f->edit('artifact/site/2.1/api-reference.html', "connect-src 'none'", 'connect-src *'), 'does not carry the exact CSP literal'],
    'markup before the CSP' => [fn (DocsStoreFixture $f) => $f->edit('artifact/site/2.1/api-reference.html', '<meta charset="utf-8">', '<meta charset="utf-8"><img src=x onerror=alert(1)>'), 'before its CSP'],
    'an inline script in the reference page' => [fn (DocsStoreFixture $f) => $f->edit('artifact/site/2.1/api-reference.html', '</body>', '<script>fetch("/x")</script></body>'), 'inline executable script'],
    'a script from another origin' => [fn (DocsStoreFixture $f) => $f->edit('artifact/site/2.1/install.html', 'src="/fieldset/2.1/assets/app.js"', 'src="https://evil.example/app.js"'), 'from another origin'],
    'a protocol-relative stylesheet' => [fn (DocsStoreFixture $f) => $f->edit('artifact/site/2.1/install.html', 'href="/fieldset/2.1/assets/style.css"', 'href="//evil.example/style.css"'), 'from another origin'],
    'an unquoted script source' => [fn (DocsStoreFixture $f) => $f->edit('artifact/site/2.1/install.html', '</head>', '<SCRIPT SRC=//evil.example/x.js></SCRIPT></head>'), 'not written as src='],
    'a base element' => [fn (DocsStoreFixture $f) => $f->edit('artifact/site/index.html', '</head>', '<base href="https://evil.example/"></head>'), 'has a <base> element'],
    'a symlink' => [fn (DocsStoreFixture $f) => symlink('/etc/passwd', $f->dir.'/artifact/site/2.1/passwd.txt'), 'symlink or other non-regular file'],
    'a dot directory' => [fn (DocsStoreFixture $f) => $f->put('artifact/site/2.1/.git/config', 'x'), 'outside the allowed names'],
    'a latest that does not follow the rules' => [fn (DocsStoreFixture $f) => $f->edit('artifact/site/versions.json', '"latest": "2.1"', '"latest": "2.2"'), 'versions.json changes more than'],
    'another tooling commit' => [fn (DocsStoreFixture $f) => $f->edit('artifact/site/versions.json', DocsStoreFixture::TOOLING, str_repeat('e', 40)), 'versions.json changes more than'],
    'an unknown top-level field' => [fn (DocsStoreFixture $f) => $f->edit('artifact/site/versions.json', '"schema": 1,', '"schema": 1, "evil": 1,'), 'versions.json changes more than'],
    'no reference page' => [fn (DocsStoreFixture $f) => File::delete($f->dir.'/artifact/site/2.1/api-reference.html'), 'has no api-reference.html'],
]);

it('rejects arguments that do not describe the verified release', function (array $overrides, string $reason): void {
    $store = $this->fixture->store($overrides);

    expect($store->getExitCode())->toBe(1)
        ->and($store->getErrorOutput())->toContain($reason);
})->with([
    'another commit' => [['--commit' => str_repeat('f', 40)], 'not at the verified commit'],
    'another minor' => [['--minor' => '2.2'], 'is not the minor of v2.1.0-rc.1'],
    'a beta tag' => [['--tag' => 'v2.1.0-beta.1'], 'is not a docs copy tag'],
    'a version before 2.1' => [['--tag' => 'v2.0.5', '--minor' => '2.0'], 'below 2.1'],
]);

it('rejects a tampered publish of a minor that is not latest', function (Closure $tamper, string $reason): void {
    $versions = docsStoreWithNewerMinor($this->fixture);
    $head = $this->fixture->git('site', 'rev-parse', 'HEAD');
    $tamper($this->fixture, $versions);
    $store = $this->fixture->store();

    expect($store->getExitCode())->toBe(1, $store->getOutput())
        ->and($store->getErrorOutput())->toContain($reason)
        ->and($this->fixture->git('site', 'rev-parse', 'HEAD'))->toBe($head);
})->with([
    'a root-owned entry' => [fn (DocsStoreFixture $f) => $f->put('artifact/site/index.html', 'x'), 'does not refresh the root'],
    'spec files' => [fn (DocsStoreFixture $f) => $f->put('artifact/site/spec/form-definition/1.0/meta-schema.json', '{}'), 'does not refresh the root'],
    'another minor\'s copy' => [fn (DocsStoreFixture $f) => $f->put('artifact/site/2.2/index.html', 'x'), 'the artifact holds 2.2'],
    'another minor\'s entry' => [fn (DocsStoreFixture $f) => $f->edit('artifact/site/versions.json', str_repeat('c', 40), str_repeat('9', 40)), 'versions.json changes more than'],
    'another minor removed' => [fn (DocsStoreFixture $f, array $v) => $f->withoutRoot([...$v, 'latest' => '2.1', 'copies' => [$v['copies'][1]]]), 'versions.json changes more than'],
]);

it('never lets a pre-release replace a final copy or an older release a newer one', function (string $stored, string $reason): void {
    docsStoreWithNewerMinor($this->fixture, $stored);
    $store = $this->fixture->store();

    expect($store->getExitCode())->toBe(1)
        ->and($store->getErrorOutput())->toContain($reason);
})->with([
    'rc over a final' => ['v2.1.0', 'has the final copy v2.1.0'],
    'older than the stored rc' => ['v2.1.0-rc.2', 'older than the stored copy v2.1.0-rc.2'],
]);
