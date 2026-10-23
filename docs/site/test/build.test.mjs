// The build orchestrator (plan U13, R9, R15, R18; AE3), run as the CLI it is: argument and input checks that stop before
// staging, a skip that writes only its decision, a publish from a fixture release repo (v2.1.0 with a typo, v2.1.1 with
// the fix) whose artifact holds only what its ownership classes allow, and real builds of the working tree (dev) and of
// the v2.1.0-rc.1 tag (check). Skipped only when the build tooling (docs/site/node_modules or git) is not installed.
import { after, before, describe, test } from 'node:test';
import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import { existsSync, readdirSync, readFileSync, writeFileSync } from 'node:fs';
import { join, relative } from 'node:path';
import { fileURLToPath } from 'node:url';
import {
  allowedPaths, checkReleaseJson, decisionRecord, parseCli, releaseFacts, ROOT_OWNED, stubExport, UsageError,
} from '../scripts/build.mjs';
import { runChecks, formatFailures } from '../lib/checks.mjs';
import { SPEC_DIR } from '../lib/root-stage.mjs';
import { decide } from '../lib/versions.mjs';
import { cleanup, tempDir, writeTree } from './support/site.mjs';

const siteDir = fileURLToPath(new URL('../', import.meta.url));
const repoRoot = fileURLToPath(new URL('../../../', import.meta.url));
const script = join(siteDir, 'scripts/build.mjs');
const RC_TAG = 'v2.1.0-rc.1';
const OTHER_SHA = 'b'.repeat(40);
after(cleanup);

const git = (cwd, ...args) => spawnSync('git', ['-C', cwd, '-c', 'core.hooksPath=/dev/null', '-c',
  'commit.gpgsign=false', '-c', 'user.name=Docs Test', '-c', 'user.email=docs-test@example.com', ...args],
{ encoding: 'utf8' });
const tooling = git(siteDir, 'rev-parse', 'HEAD').stdout?.trim();
const missingTooling = ['vitepress', '@scalar/api-reference']
  .map((name) => join(siteDir, 'node_modules', name, 'package.json')).find((file) => !existsSync(file))
  ?? (/^[0-9a-f]{40}$/.test(tooling ?? '') ? null : 'git');
const skipBuilds = missingTooling ? `${missingTooling} is not installed` : false;

const build = (...args) => spawnSync(process.execPath, [script, ...args], { encoding: 'utf8', maxBuffer: 1 << 28 });
const entries = (dir) => readdirSync(dir).sort();
const json = (file) => JSON.parse(readFileSync(file, 'utf8'));
function filesBelow(dir) {
  return readdirSync(dir, { recursive: true, withFileTypes: true }).filter((entry) => !entry.isDirectory())
    .map((entry) => relative(dir, join(entry.parentPath, entry.name)).split('\\').join('/')).sort();
}
const allowedBy = (paths, file) => paths.some((path) => (path.endsWith('/') ? file.startsWith(path) : file === path));

// A release repo with the pages the default literal checks read; v2.1.0 has a typo that v2.1.1 fixes (AE3).
const page = (title, body) => `# ${title}\n\n${body}\n`;
const RELEASE_PAGES = (installText) => ({
  'docs/guide/index.md': page('Guides', '## Start\n\n| Guide | For |\n|---|---|\n| [Install](install.md) | operators |\n'
    + '| [Admin guide](admin-guide.md) | admins |\n| [Field App guide](field-app-guide.md) | field staff |\n'
    + '| [Troubleshooting](troubleshooting.md) | everyone |\n\nThe [changelog](../../CHANGELOG.md) and the '
    + '[Field App API](../api/field-app-v1.md).'),
  'docs/guide/install.md': page('Install Fieldset', installText),
  'docs/guide/admin-guide.md': page('Admin guide', 'Templates may use `{{org.name}}`.'),
  'docs/guide/field-app-guide.md': page('Field App guide', 'The button reads `Weiter mit <organization>` '
    + '(`Continue with <organization>`).'),
  'docs/guide/troubleshooting.md': page('Troubleshooting', '`Single Sign-on erfordert Ihre Aufmerksamkeit: <provider>`'),
  'docs/guide/archive/1.0-troubleshooting.md': page('Troubleshooting 1.0',
    '`Single Sign-on erfordert Ihre Aufmerksamkeit: <provider>`'),
  'docs/api/field-app-v1.md': page('Field App API v1', 'The sign-in button reads `Continue with <organization>`.'),
  'CHANGELOG.md': page('Changelog', '## 2.1.0\n\n- First release with a site.'),
  [`${SPEC_DIR}/README.md`]: page('Fieldset Spec 1.0', 'See [meta-schema.json](meta-schema.json).'),
  [`${SPEC_DIR}/CHANGELOG.md`]: page('Changelog — Fieldset Spec 1.0', '## 1.0.0'),
  [`${SPEC_DIR}/meta-schema.json`]: '{"$id":"https://beyerenrico.github.io/fieldset/spec/form-definition/1.0/meta-schema.json"}\n',
});
const TYPO = 'Instal Fieldset with one command.';
const FIXED = 'Install Fieldset with one command.';

function commitAndTag(repo, tag) {
  for (const args of [['add', '-A'], ['commit', '-q', '-m', tag], ['tag', tag]]) {
    const result = git(repo, ...args);
    assert.equal(result.status, 0, result.stderr);
  }
}

function releaseRepo() {
  const repo = tempDir('fieldset-build-repo-');
  assert.equal(git(repo, 'init', '-q', '-b', 'main').status, 0);
  writeTree(repo, RELEASE_PAGES(TYPO));
  commitAndTag(repo, 'v2.1.0');
  writeTree(repo, { 'docs/guide/install.md': page('Install Fieldset', FIXED) });
  commitAndTag(repo, 'v2.1.1');
  writeTree(repo, { 'docs/guide/field-app-guide.md': page('Field App guide', 'The button names the organization.') });
  commitAndTag(repo, 'v2.1.2'); // drops two literals the checks require
  return repo;
}

function worktreeAt(repo, tag) {
  const dir = join(tempDir('fieldset-build-src-'), 'source');
  const result = git(repo, 'worktree', 'add', '-q', '--detach', dir, tag);
  assert.equal(result.status, 0, result.stderr);
  return dir;
}

const copyEntry = (version, builtWith = OTHER_SHA) => ({
  minor: version.split('.').slice(0, 2).join('.'), version, tag: `v${version}`, prerelease: version.includes('-'),
  builtWith,
});
const stored = (latest, ...versions) => ({ schema: 1, latest, copies: versions.map((version) => copyEntry(version)) });
const releaseJson = (tag) => {
  const { version, channel, prerelease } = releaseFacts(tag);
  return { version, tag, channel, prerelease, image_tags: [version] };
};

// The publish command line for tag from source, with overrides by option name (null drops the option).
function publishArgs({ source, tag, out, store, overrides = {} }) {
  const work = tempDir('fieldset-build-in-');
  const files = { export: stubExport(), stored: store, 'release-json': releaseJson(tag) };
  const paths = Object.fromEntries(Object.entries(files).map(([name, value]) => {
    if (name === 'stored' && value === null) return [name, 'none'];
    const file = join(work, `${name}.json`);
    writeFileSync(file, JSON.stringify(value));
    return [name, file];
  }));
  const options = { source, tag, out, ...paths, 'built-with': tooling, ...overrides };
  return ['publish', ...Object.entries(options).filter(([, value]) => value !== null)
    .flatMap(([name, value]) => [`--${name}`, value])];
}

describe('the command line', () => {
  test('takes each mode with exactly its options', () => {
    assert.deepEqual(parseCli(['dev', '--out', 'o', '--stub-export']).mode, 'dev');
    assert.equal(parseCli(['check', '--source', 's', '--tag', 'v2.1.0', '--out', 'o']).options.tag, 'v2.1.0');
    const rejected = [[], ['deploy', '--out', 'o'], ['dev', '--out', 'o'], ['dev', '--out', 'o', '--stub-export',
      '--export', 'e'], ['check', '--source', 's', '--tag', 'v2.1.0', '--out', 'o', '--export', 'e'],
    ['dev', '--out', 'o', '--out', 'p', '--stub-export'], ['dev', '--out', '', '--stub-export'],
    ['publish', '--stub-export', '--out', 'o'], ['dev', '--out', 'o', '--stub-export', '--secret', 'x']];
    for (const argv of rejected) assert.throws(() => parseCli(argv), UsageError, argv.join(' '));
  });

  test('checks the release JSON against the tag', () => {
    const facts = releaseFacts('v2.1.0-rc.1');
    assert.deepEqual(facts, { tag: 'v2.1.0-rc.1', version: '2.1.0-rc.1', minor: '2.1', prerelease: true, channel: 'rc' });
    checkReleaseJson(releaseJson('v2.1.0-rc.1'), facts);
    assert.throws(() => checkReleaseJson({ ...releaseJson('v2.1.0-rc.1'), channel: 'stable', prerelease: false }, facts),
      /does not describe v2\.1\.0-rc\.1: channel is "stable", expected "rc"; prerelease is false, expected true$/);
    assert.throws(() => checkReleaseJson(releaseJson('v2.1.0'), facts), /version is "2\.1\.0"/);
    assert.throws(() => checkReleaseJson([], facts), /not a JSON object/);
    assert.throws(() => releaseFacts('2.1.0'), UsageError);
  });

  test('records the decision with the top-level paths the store may change', () => {
    const facts = releaseFacts('v2.1.1');
    // With 2.1 the only copy, v2.1.1 becomes the newest final, so the root-refresh rule refreshes the root too.
    const only = decisionRecord(decide(stored('2.1', '2.1.0'), { tag: 'v2.1.1', builtWith: tooling }), facts);
    assert.deepEqual(only, { action: 'publish', reason: '2.1.1 replaces 2.1.0', tag: 'v2.1.1', minor: '2.1',
      version: '2.1.1', refreshRoot: true, allowedPaths: ['2.1/', 'versions.json', ...ROOT_OWNED, `${SPEC_DIR}/`] });
    assert.deepEqual(allowedPaths({ minor: '2.2', refreshRoot: false }), ['2.2/', 'versions.json']);
  });
});

describe('publish', { skip: skipBuilds }, () => {
  let repo;
  before(() => { repo = releaseRepo(); });

  test('without --tag or --source exits before staging', () => {
    const source = worktreeAt(repo, 'v2.1.1');
    for (const option of ['tag', 'source']) {
      const out = join(tempDir(), 'out');
      const result = build(...publishArgs({ source, tag: 'v2.1.1', out, store: null, overrides: { [option]: null } }));
      assert.equal(result.status, 2, result.stderr);
      assert.match(result.stderr, new RegExp(`publish requires --${option} \\(no defaults\\)`));
      assert.equal(result.stdout, '');
      assert.ok(!existsSync(out), `${out} was created`);
    }
  });

  test('refuses inputs that do not belong to the tag, before staging', () => {
    const source = worktreeAt(repo, 'v2.1.0');
    const cases = [
      [{}, 'v2.1.1', /--source .* is at [0-9a-f]{40}, not at v2\.1\.1/],
      [{ stored: join(tempDir(), 'missing.json') }, 'v2.1.0', /--stored .*missing\.json cannot be read \(ENOENT\)/],
      [{ 'built-with': OTHER_SHA }, 'v2.1.0', /--built-with b{40} is not the tooling checkout's commit/],
      [{ tag: 'v2.1.0-rc.1' }, 'v2.1.0', /--release-json does not describe v2\.1\.0-rc\.1: version is "2\.1\.0"/],
    ];
    for (const [overrides, tag, message] of cases) {
      const out = join(tempDir(), 'out');
      const result = build(...publishArgs({ source, tag, out, store: null, overrides }));
      assert.equal(result.status, 1, `${message}: ${result.stderr}`);
      assert.match(result.stderr, message);
      assert.ok(!existsSync(out), `${out} was created`);
    }
  });

  test('a skip decision writes only decision.json and exits 0 with the reason', () => {
    const out = join(tempDir(), 'out');
    const source = worktreeAt(repo, 'v2.1.0');
    const result = build(...publishArgs({ source, tag: 'v2.1.0', out, store: stored('2.1', '2.1.1') }));
    assert.equal(result.status, 0, result.stderr);
    assert.match(result.stdout, /Skip v2\.1\.0: older than the stored 2\.1\.1/);
    assert.deepEqual(entries(out), ['decision.json']);
    assert.deepEqual(json(join(out, 'decision.json')), { action: 'skip', reason: 'older than the stored 2.1.1',
      tag: 'v2.1.0', minor: '2.1', version: '2.1.0', refreshRoot: false, allowedPaths: [] });
  });

  // AE3 with 2.2 already published: the 2.1.1 copy shows the fix; no other copy and no root part is written.
  test('v2.1.1 over {2.2.0, 2.1.0} writes only 2.1/ and versions.json (AE3)', () => {
    const out = join(tempDir(), 'out');
    const store = stored('2.2', '2.2.0', '2.1.0');
    const result = build(...publishArgs({ source: worktreeAt(repo, 'v2.1.1'), tag: 'v2.1.1', out, store }));
    assert.equal(result.status, 0, `${result.stdout}\n${result.stderr}`);
    assert.match(result.stdout, /Post-build checks passed \(.*reference 1.*\); not run: spec, screenshots, fallback/);
    const decision = json(join(out, 'decision.json'));
    assert.deepEqual(decision, { action: 'publish', reason: '2.1.1 replaces 2.1.0', tag: 'v2.1.1', minor: '2.1',
      version: '2.1.1', refreshRoot: false, allowedPaths: ['2.1/', 'versions.json'] });
    assert.deepEqual(entries(out), ['decision.json', 'site']);
    assert.deepEqual(entries(join(out, 'site')), ['2.1', 'versions.json']);
    assert.deepEqual(filesBelow(join(out, 'site')).filter((file) => !allowedBy(decision.allowedPaths, file)), []);
    const install = readFileSync(join(out, 'site/2.1/install.html'), 'utf8');
    assert.ok(install.includes(FIXED) && !install.includes(TYPO));
    assert.ok(existsSync(join(out, 'site/2.1/api-reference.html')));
    assert.deepEqual(json(join(out, 'site/versions.json')), { schema: 1, latest: '2.2',
      copies: [store.copies[0], copyEntry('2.1.1', tooling)] });
  });

  // AE3 with 2.1 the only copy: v2.1.1 becomes the newest final, so the root refreshes too; still no other copy.
  test('v2.1.1 over {2.1.0} writes 2.1/, versions.json and the root parts, and nothing else', () => {
    const out = join(tempDir(), 'out');
    const source = worktreeAt(repo, 'v2.1.1');
    const result = build(...publishArgs({ source, tag: 'v2.1.1', out, store: stored('2.1', '2.1.0') }));
    assert.equal(result.status, 0, `${result.stdout}\n${result.stderr}`);
    assert.doesNotMatch(result.stdout, /not run:/);
    const decision = json(join(out, 'decision.json'));
    assert.equal(decision.refreshRoot, true);
    assert.deepEqual(decision.allowedPaths, allowedPaths({ minor: '2.1', refreshRoot: true }));
    assert.deepEqual(entries(join(out, 'site')),
      ['2.1', '404.html', 'assets', 'hashmap.json', 'index.html', 'latest', 'screenshots', 'spec', 'versions.json']);
    assert.deepEqual(filesBelow(join(out, 'site')).filter((file) => !allowedBy(decision.allowedPaths, file)), []);
    const metaSchema = `${SPEC_DIR}/meta-schema.json`;
    assert.ok(readFileSync(join(out, 'site', metaSchema)).equals(readFileSync(join(source, metaSchema))));
    assert.match(readFileSync(join(out, 'site/index.html'), 'utf8'), /releases\/download\/v2\.1\.1\/install\.sh/);
  });

  test('a failed check exits 1 and leaves no artifact', () => {
    const out = join(tempDir(), 'out');
    const store = stored('2.2', '2.2.0', '2.1.1');
    const result = build(...publishArgs({ source: worktreeAt(repo, 'v2.1.2'), tag: 'v2.1.2', out, store }));
    assert.equal(result.status, 1, result.stdout);
    assert.match(result.stderr, /Post-build checks failed \(2\):\n\[literals\] 2\.1\/field-app-guide\.html: does not show/);
    assert.deepEqual(entries(out), []);
  });
});

describe('dev', { skip: skipBuilds }, () => {
  test('refuses an invalid --versions file before staging', () => {
    const out = join(tempDir(), 'out');
    const versions = join(tempDir(), 'versions.json');
    writeFileSync(versions, JSON.stringify({ schema: 1, latest: '2.1', copies: [] }));
    const result = build('dev', '--out', out, '--stub-export', '--versions', versions);
    assert.equal(result.status, 1, result.stderr);
    assert.match(result.stderr, /--versions .*versions\.json: versions is not a valid versions\.json/);
    assert.equal(result.stdout, '');
    assert.ok(!existsSync(out));
  });

  test('builds the working tree with a stub export into dev/ plus the root, passing every check', () => {
    const out = join(tempDir(), 'out');
    const result = build('dev', '--out', out, '--stub-export');
    assert.equal(result.status, 0, `${result.stdout}\n${result.stderr}`);
    assert.match(result.stdout, /Post-build checks passed/);
    assert.doesNotMatch(result.stdout, /not run:/);
    const top = entries(out);
    assert.deepEqual(top.filter((name) => !['dev', 'spec', ...ROOT_OWNED.map((entry) => entry.replace(/\/$/, ''))]
      .includes(name)), []);
    for (const file of ['dev/index.html', 'dev/api-reference.html', 'index.html', '404.html', 'latest/index.html',
      `${SPEC_DIR}/meta-schema.json`]) assert.ok(existsSync(join(out, file)), file);
    assert.ok(!top.includes('versions.json') && !top.includes('decision.json'));
    const checks = runChecks({ outDir: out, copy: 'dev', root: true, mode: 'dev', specSourceDir: join(repoRoot, SPEC_DIR) });
    assert.deepEqual(checks.failures, [], formatFailures(checks.failures));
    assert.deepEqual(checks.skipped, []);
  });
});

const rcAvailable = spawnSync('git', ['-C', repoRoot, 'rev-parse', '--verify', '--quiet', `refs/tags/${RC_TAG}^{commit}`])
  .status === 0;

describe('check', { skip: skipBuilds || (rcAvailable ? false : `the tag ${RC_TAG} is not fetched`) }, () => {
  let source;
  before(() => { source = worktreeAt(repoRoot, RC_TAG); });
  after(() => source && spawnSync('git', ['-C', repoRoot, 'worktree', 'remove', '--force', source]));

  test(`builds ${RC_TAG} content-only: no decision, no artifact, only the reference checks skipped`, () => {
    const out = join(tempDir(), 'out');
    const result = build('check', '--source', source, '--tag', RC_TAG, '--out', out);
    assert.equal(result.status, 0, `${result.stdout}\n${result.stderr}`);
    assert.match(result.stdout, /Post-build checks passed \(.*\); not run: reference$/m);
    const top = entries(out);
    assert.ok(top.includes('2.1') && top.includes('index.html') && top.includes('spec'));
    assert.ok(!['site', 'decision.json', 'versions.json'].some((name) => top.includes(name)));
    const checks = runChecks({ outDir: out, copy: '2.1', root: true, mode: 'check',
      specSourceDir: join(source, SPEC_DIR) });
    assert.deepEqual(checks.failures, [], formatFailures(checks.failures));
    assert.deepEqual(checks.skipped, ['reference']);
    assert.match(readFileSync(join(out, '2.1/install.html'), 'utf8'), /releases\/download\/v2\.1\.0-rc\.1\/install\.sh/);
  });
});
