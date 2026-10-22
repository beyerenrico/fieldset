// Staging the root (plan U4, R4, R5, R11): the spec README and changelog as text pages through the U2 rules, every JSON
// file of the spec directory byte for byte, the site-only pages from the root content dir and the baked versions.json.
import { after, describe, test } from 'node:test';
import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import { mkdirSync, readdirSync, readFileSync, rmSync, symlinkSync } from 'node:fs';
import { join, relative } from 'node:path';
import { fileURLToPath } from 'node:url';
import {
  readRootSite, rootBuild, rootContentDir, ROOT_PAGES, rootVersions, stageRoot, TOOLING_CONTENT_DIR,
} from '../lib/root-stage.mjs';
import { wrapVPre } from '../lib/stage.mjs';
import { decide } from '../lib/versions.mjs';
import { anchor, cleanup, RC, render, rendererFor, tempDir, writeTree } from './support/site.mjs';

const repoRoot = fileURLToPath(new URL('../../../', import.meta.url));
const SPEC = 'spec/form-definition/1.0';
const RC_TAG = 'v2.1.0-rc.1';
const DEV = { ...RC, minor: 'dev', version: 'dev', tag: null, ref: 'c'.repeat(40), prerelease: false };
after(cleanup);

const storeOf = (...tags) => tags.reduce((stored, tag) => decide(stored, { tag, builtWith: 'a'.repeat(40) }).versions, null);
const git = (...args) => spawnSync('git', ['-C', repoRoot, ...args], { maxBuffer: 1 << 26 });
const tagFetched = git('rev-parse', '--verify', '--quiet', `${RC_TAG}^{commit}`).status === 0;

function filesBelow(dir) {
  return readdirSync(dir, { recursive: true, withFileTypes: true })
    .filter((entry) => !entry.isDirectory())
    .map((entry) => relative(dir, join(entry.parentPath, entry.name)).split('\\').join('/'))
    .sort();
}

// Bytes a text editor would normalize: CRLF, no final newline, a non-ASCII character.
const META_SCHEMA = Buffer.from('{\r\n  "$id": "https://beyerenrico.github.io/fieldset/spec/form-definition/1.0/'
  + 'meta-schema.json",\r\n  "title": "Größe"}');
const SOURCE = {
  [`${SPEC}/README.md`]: '# Fieldset Spec 1.0\n\nMeta-schema: [`meta-schema.json`](meta-schema.json) · Roles: [`roles.json`]'
    + '(roles.json) · Changes: [`CHANGELOG.md`](CHANGELOG.md) · Conformance: [`../../conformance/`](../../conformance)\n'
    + '\nA key like <organization> stays text.\n',
  [`${SPEC}/CHANGELOG.md`]: '# Changelog — Fieldset Spec 1.0\n\nSee the [spec](README.md#fieldset-spec-10).\n',
  [`${SPEC}/meta-schema.json`]: META_SCHEMA,
  [`${SPEC}/roles.json`]: '{"roles": []}\n',
  'spec/conformance/README.md': '# Conformance\n',
};
const CONTENT = {
  'index.md': '---\nlayout: home\n---\n',
  '404.md': '---\nlayout: FieldsetRedirect\n---\n',
  'latest/index.md': '---\nlayout: FieldsetRedirect\n---\n',
  '.vitepress/config.mjs': 'export default {};\n',
  'notes.txt': 'not staged\n',
  'public/screenshots/forms-list.png': 'png',
};

function stage({ source = SOURCE, content = CONTENT, descriptor = RC, versions = storeOf(RC_TAG), files } = {}) {
  const sourceDir = writeTree(tempDir(), source);
  const contentDir = writeTree(tempDir(), content);
  const out = join(tempDir(), 'stage');
  const result = stageRoot({
    source: sourceDir, out, descriptor, versions, contentDir, files: files ?? Object.keys(source),
  });
  return { out, sourceDir, contentDir, ...result };
}

describe('staging the root', () => {
  test('stages the spec pages, every spec JSON byte for byte, the root pages and the baked versions', () => {
    const { out, pages, specFiles, content } = stage();
    assert.deepEqual(pages.sort(), [`${SPEC}/CHANGELOG.md`, `${SPEC}/README.md`]);
    assert.deepEqual(specFiles, [`${SPEC}/meta-schema.json`, `${SPEC}/roles.json`]);
    assert.deepEqual(content, [...ROOT_PAGES, 'public/screenshots/forms-list.png']);
    assert.deepEqual(filesBelow(out), [
      '404.md', 'index.md', 'latest/index.md', 'public/screenshots/forms-list.png', `public/${SPEC}/meta-schema.json`,
      `public/${SPEC}/roles.json`, 'repo-files.json', 'root.json', 'site.json', `${SPEC}/CHANGELOG.md`, `${SPEC}/README.md`,
    ]);
    assert.ok(readFileSync(join(out, `public/${SPEC}/meta-schema.json`)).equals(META_SCHEMA));
    assert.equal(readFileSync(join(out, `${SPEC}/README.md`), 'utf8'), wrapVPre(SOURCE[`${SPEC}/README.md`]));
    assert.deepEqual(JSON.parse(readFileSync(join(out, 'root.json'), 'utf8')), {
      versions: { schema: 1, latest: '2.1', copies: [{ minor: '2.1', version: '2.1.0-rc.1', tag: RC_TAG, prerelease: true }] },
    });
  });

  test('the root build profile serves the spec at /fieldset/spec/form-definition/1.0/', () => {
    const site = readRootSite(stage().out);
    assert.equal(site.base, '/fieldset/');
    assert.equal(site.pagePath(`${SPEC}/README.md`), `${SPEC}/index.md`);
    assert.equal(site.pagePath(`${SPEC}/CHANGELOG.md`), `${SPEC}/changelog.md`);
    assert.equal(site.versions.latest, '2.1');
    assert.equal(rootBuild.pagePath('index.md'), null);
    assert.equal(rootBuild.pagePath('toString'), null);
    assert.equal(rootBuild.crossBuildUrl(`${SPEC}/roles.json`), `/fieldset/${SPEC}/roles.json`);
    assert.equal(rootBuild.crossBuildUrl(`${SPEC}/sub/x.json`), null);
    assert.equal(rootBuild.crossBuildUrl(`${SPEC}/README.md`), null);
  });

  test('the README links stay raw files beside the page, open the changelog page and conformance on GitHub', async () => {
    const site = readRootSite(stage().out);
    const md = await rendererFor(site);
    const readme = (await render(md, site, `${SPEC}/README.md`)).html;
    const link = (html, text) => {
      const { href, target } = anchor(html, text);
      return { href, target };
    };
    assert.deepEqual(link(readme, '<code>meta-schema.json</code>'), { href: 'meta-schema.json', target: '_self' });
    assert.deepEqual(link(readme, '<code>roles.json</code>'), { href: 'roles.json', target: '_self' });
    assert.equal(anchor(readme, '<code>CHANGELOG.md</code>').href, `/fieldset/${SPEC}/changelog`);
    assert.equal(anchor(readme, '<code>../../conformance/</code>').href,
      `https://github.com/beyerenrico/fieldset/tree/${RC_TAG}/spec/conformance`);
    assert.match(readme, /A key like &lt;organization&gt; stays text\./);
    const changelog = (await render(md, site, `${SPEC}/CHANGELOG.md`)).html;
    assert.equal(anchor(changelog, 'spec').href, `/fieldset/${SPEC}/#fieldset-spec-10`);
  });

  test('a working-tree build takes any versions.json or none', () => {
    assert.equal(stage({ descriptor: DEV, versions: null }).descriptor.tag, null);
    assert.equal(readRootSite(stage({ descriptor: DEV, versions: null }).out).versions, null);
    assert.equal(readRootSite(stage({ descriptor: DEV, versions: storeOf('v2.1.0', 'v2.2.0') }).out).versions.latest, '2.2');
  });

  test('the content dir is the release tree\'s docs/site/root when it has a home page, else this tooling\'s', () => {
    const withRoot = writeTree(tempDir(), { 'docs/site/root/index.md': '# Home\n' });
    assert.equal(rootContentDir(withRoot), join(withRoot, 'docs/site/root'));
    assert.equal(rootContentDir(writeTree(tempDir(), { 'docs/site/root/notes.txt': 'x' })), TOOLING_CONTENT_DIR);
    assert.equal(rootContentDir(repoRoot), join(repoRoot, 'docs/site/root'));
    assert.deepEqual(ROOT_PAGES.map((page) => readFileSync(join(TOOLING_CONTENT_DIR, page), 'utf8').startsWith('---\n')),
      [true, true, true]);
  });
});

describe('rejected input', () => {
  test('a release that is not versions.json\'s latest copy cannot build the root', () => {
    assert.throws(() => stage({ versions: storeOf('v2.1.0') }), /v2\.1\.0-rc\.1 is not versions\.json's latest copy/);
    assert.throws(() => stage({ versions: null }), /is not versions\.json's latest copy/);
    assert.throws(() => stage({ descriptor: { ...RC, version: '2.2.0-rc.1', tag: 'v2.2.0-rc.1', minor: '2.2' },
      versions: storeOf('v2.1.0', 'v2.2.0-rc.1') }), /not versions\.json's latest copy/);
  });

  test('a malformed versions.json fails', () => {
    for (const versions of [{ schema: 2, latest: '2.1', copies: [] }, { schema: 1, latest: '2.2', copies: [] }, 'x']) {
      assert.throws(() => stage({ descriptor: DEV, versions }), /versions is not a valid versions\.json/);
    }
  });

  test('missing spec files fail, naming them', () => {
    const source = { ...SOURCE };
    delete source[`${SPEC}/CHANGELOG.md`];
    delete source[`${SPEC}/meta-schema.json`];
    assert.throws(() => stage({ source }),
      /lacks spec files: spec\/form-definition\/1\.0\/CHANGELOG\.md, spec\/form-definition\/1\.0\/meta-schema\.json/);
  });

  test('links, a missing root page and badly named screenshots fail', () => {
    const linked = (path, target) => {
      const contentDir = writeTree(tempDir(), Object.fromEntries(Object.entries(CONTENT).filter(([key]) => key !== path)));
      mkdirSync(join(contentDir, path, '..'), { recursive: true });
      symlinkSync(target, join(contentDir, path));
      return contentDir;
    };
    const run = (contentDir) => stageRoot({
      source: writeTree(tempDir(), SOURCE), out: join(tempDir(), 'stage'), descriptor: RC, versions: storeOf(RC_TAG),
      contentDir, files: Object.keys(SOURCE),
    });
    assert.throws(() => run(linked('latest/index.md', '/etc/hosts')), /Root page latest\/index\.md must be a regular file/);
    assert.throws(() => run(linked('public/screenshots/x.png', '/etc/hosts')), /Screenshot x\.png must be a regular file/);
    const shotsLinked = writeTree(tempDir(), { 'index.md': '', '404.md': '', 'latest/index.md': '' });
    mkdirSync(join(shotsLinked, 'public'));
    symlinkSync(tempDir(), join(shotsLinked, 'public/screenshots'));
    assert.throws(() => run(shotsLinked), /screenshots must be a directory, not a link/);
    assert.throws(() => stage({ content: { ...CONTENT, 'public/screenshots/x.svg': '<svg/>' } }),
      /Screenshot x\.svg must be named/);
    const noLatest = { ...CONTENT };
    delete noLatest['latest/index.md'];
    assert.throws(() => stage({ content: noLatest }), /Root page latest\/index\.md is missing/);
  });

  test('a linked spec JSON file fails', () => {
    const sourceDir = writeTree(tempDir(), SOURCE);
    assert.throws(() => stageRoot({
      source: sourceDir, out: join(tempDir(), 'stage'), descriptor: RC, versions: storeOf(RC_TAG),
      contentDir: writeTree(tempDir(), CONTENT), files: [...Object.keys(SOURCE), `${SPEC}/linked.json`],
    }), /Spec file spec\/form-definition\/1\.0\/linked\.json is missing/);
    symlinkSync('/etc/hosts', join(sourceDir, `${SPEC}/linked.json`));
    assert.throws(() => stageRoot({
      source: sourceDir, out: join(tempDir(), 'stage'), descriptor: RC, versions: storeOf(RC_TAG),
      contentDir: writeTree(tempDir(), CONTENT), files: [...Object.keys(SOURCE), `${SPEC}/linked.json`],
    }), /Spec file spec\/form-definition\/1\.0\/linked\.json must be a regular file/);
  });

  test('a linked spec page fails', () => {
    const sourceDir = writeTree(tempDir(), { ...SOURCE });
    rmSync(join(sourceDir, `${SPEC}/README.md`));
    symlinkSync('/etc/hosts', join(sourceDir, `${SPEC}/README.md`));
    assert.throws(() => stageRoot({
      source: sourceDir, out: join(tempDir(), 'stage'), descriptor: RC, versions: storeOf(RC_TAG),
      contentDir: writeTree(tempDir(), CONTENT), files: Object.keys(SOURCE),
    }), /Spec file spec\/form-definition\/1\.0\/README\.md must be a regular file/);
  });

  test('rootVersions keeps only the fields the pages read', () => {
    assert.deepEqual(rootVersions({ ...storeOf('v2.1.0'), extra: true }), {
      schema: 1, latest: '2.1', copies: [{ minor: '2.1', version: '2.1.0', tag: 'v2.1.0', prerelease: false }],
    });
  });
});

describe(`the ${RC_TAG} tree`, { skip: !tagFetched && `tag ${RC_TAG} is not fetched` }, () => {
  test('predates docs/site/root, so the tooling pages stage, with the tag\'s spec JSON byte for byte', () => {
    const files = git('ls-tree', '-r', '-z', '--name-only', RC_TAG).stdout.toString().split('\0').filter(Boolean);
    const spec = files.filter((path) => path.startsWith(`${SPEC}/`));
    const source = writeTree(tempDir(), Object.fromEntries(spec.map((path) => [path, git('show', `${RC_TAG}:${path}`).stdout])));
    assert.equal(rootContentDir(source), TOOLING_CONTENT_DIR);
    const out = join(tempDir(), 'stage');
    const { specFiles } = stageRoot({ source, out, descriptor: RC, versions: storeOf(RC_TAG), files });
    assert.deepEqual(specFiles, [`${SPEC}/meta-schema.json`, `${SPEC}/roles.json`]);
    for (const path of specFiles) {
      assert.ok(readFileSync(join(out, 'public', path)).equals(git('show', `${RC_TAG}:${path}`).stdout), path);
    }
  });
});
