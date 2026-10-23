// Staging (plan U2, R1, R3): the allowlist under repo paths, each page in a v-pre wrapper, the site descriptor, and
// rejection of VitePress features that run before markdown-it, naming file and line.
import { after, describe, test } from 'node:test';
import assert from 'node:assert/strict';
import { readdirSync, readFileSync } from 'node:fs';
import { join, relative } from 'node:path';
import { fileURLToPath } from 'node:url';
import { listRepoFiles, readSite, sourceProblems, stageContent, wrapVPre } from '../lib/stage.mjs';
import { cleanup, RC, tempDir, writeTree } from './support/site.mjs';

const repoRoot = fileURLToPath(new URL('../../../', import.meta.url));
after(cleanup);

function filesBelow(dir) {
  return readdirSync(dir, { recursive: true, withFileTypes: true })
    .filter((entry) => entry.isFile())
    .map((entry) => relative(dir, join(entry.parentPath, entry.name)).split('\\').join('/'))
    .sort();
}

function stage(pages, { otherFiles = [], descriptor = RC } = {}) {
  const source = writeTree(tempDir(), pages);
  const out = join(tempDir(), 'stage');
  return { out, ...stageContent({ source, out, descriptor, files: [...Object.keys(pages), ...otherFiles] }) };
}

const PAGES = {
  'docs/guide/index.md': '# Guides\n',
  'docs/guide/archive/1.0-install.md': '# Install 1.0\n',
  'docs/api/field-app-v1.md': '# API\n',
  'docs/api/notes.md': '# Not staged\n',
  'docs/adr/0001-record.md': '# Not staged\n',
  'CHANGELOG.md': '# Changelog\n',
  'README.md': '# Not staged\n',
  'spec/form-definition/1.0/README.md': '# Spec, root build\n',
};

describe('staging', () => {
  test('copies the allowlist only, under repo paths, with descriptor and repo file index', () => {
    const { out, pages } = stage(PAGES, { otherFiles: ['SECURITY.md'] });
    assert.deepEqual(pages.sort(), ['CHANGELOG.md', 'docs/api/field-app-v1.md', 'docs/guide/archive/1.0-install.md',
      'docs/guide/index.md']);
    assert.deepEqual(filesBelow(out), [...pages, 'repo-files.json', 'site.json'].sort());
    assert.deepEqual(JSON.parse(readFileSync(join(out, 'site.json'), 'utf8')), {
      minor: '2.1', version: '2.1.0-rc.1', tag: 'v2.1.0-rc.1', ref: 'v2.1.0-rc.1', prerelease: true,
      repository: 'beyerenrico/fieldset',
    });
    const site = readSite(out);
    assert.equal(site.base, '/fieldset/2.1/');
    assert.ok(site.files.has('SECURITY.md') && site.dirs.has('docs/adr') && site.dirs.has(''));
  });

  test('wraps each page in a v-pre container that no line of the page can close', () => {
    const { out } = stage({ 'docs/guide/index.md': '# Guides\n\n::: tip\nx\n:::\n\n  :::::\n' });
    const staged = readFileSync(join(out, 'docs/guide/index.md'), 'utf8');
    assert.equal(staged, ':::::: v-pre\n# Guides\n\n::: tip\nx\n:::\n\n  :::::\n::::::\n');
    assert.equal(wrapVPre('no newline at the end'), '::: v-pre\nno newline at the end\n:::\n');
  });

  test('records prerelease and the commit ref of a working-tree build from the descriptor input', () => {
    const commit = 'b'.repeat(40);
    const input = { ...RC, minor: 'dev', version: 'dev', tag: null, ref: commit, prerelease: false };
    const { descriptor } = stage(PAGES, { descriptor: input });
    assert.deepEqual(descriptor, { minor: 'dev', version: 'dev', tag: null, ref: commit, prerelease: false,
      repository: 'beyerenrico/fieldset' });
  });

  test('staging the working tree copies the allowlist only and records prerelease', () => {
    const out = join(tempDir(), 'stage');
    const { pages, descriptor } = stageContent({ source: repoRoot, out, descriptor: { ...RC, prerelease: false } });
    const expected = listRepoFiles(repoRoot)
      .filter((path) => /^docs\/guide\/.+\.md$/.test(path) || /^(docs\/api\/field-app-v1|CHANGELOG)\.md$/.test(path));
    assert.ok(pages.includes('docs/guide/agent-guide.md') && pages.includes('docs/guide/archive/1.0-upgrade.md'));
    assert.deepEqual(pages, expected);
    assert.deepEqual(filesBelow(out), [...pages, 'repo-files.json', 'site.json'].sort());
    assert.equal(descriptor.prerelease, false);
    assert.equal(readFileSync(join(out, 'docs/guide/agent-guide.md'), 'utf8'),
      wrapVPre(readFileSync(join(repoRoot, 'docs/guide/agent-guide.md'), 'utf8')));
  });
});

describe('rejected input', () => {
  test('front matter, @include, <<< imports and <script>/<style> blocks fail naming file and line', () => {
    const pages = {
      'docs/guide/index.md': '---\ntitle: x\n---\n# Guides\n',
      'docs/guide/install.md': '# Install\n\ntext <!--@include: ./parts/a.md--> more\n',
      'docs/guide/upgrade.md': '# Upgrade\n\n- step\n\n  <<< @/snippets/upgrade.sh\n',
      'CHANGELOG.md': '# Changelog\n\n<script setup>\nconst x = 1\n</script>\n\n<style>\np {}\n</style>\n',
    };
    assert.throws(() => stage(pages), (error) => {
      assert.match(error.message, /docs\/guide\/index\.md:1: front matter is not allowed/);
      assert.match(error.message, /docs\/guide\/install\.md:3: an `<!--@include:` directive is not allowed/);
      assert.match(error.message, /docs\/guide\/upgrade\.md:5: a `<<<` snippet import is not allowed/);
      assert.match(error.message, /CHANGELOG\.md:3: a <script> or <style> block is not allowed/);
      assert.match(error.message, /CHANGELOG\.md:7: a <script> or <style> block is not allowed/);
      return true;
    });
  });

  test('inside a fenced code block, snippet and script lines are only text', () => {
    const text = '# Embed\n\n````html\n```\n<script src="x.js"></script>\n```\n<<< not a snippet\n````\n';
    assert.deepEqual(sourceProblems('docs/guide/embed.md', text), []);
    assert.deepEqual(sourceProblems('docs/guide/embed.md', '```\nx\n```\n<style>\n'),
      ['docs/guide/embed.md:4: a <script> or <style> block is not allowed on the site']);
  });

  test('an @include inside a fence still fails, because VitePress expands it before parsing', () => {
    assert.equal(sourceProblems('x.md', '```\n<!-- @include: ./a.md -->\n```\n').length, 1);
  });

  test('front matter after a byte-order mark fails; a rule further down is no front matter', () => {
    assert.deepEqual(sourceProblems('x.md', '\uFEFF---\nlayout: home\n---\n'),
      ['x.md:1: front matter is not allowed on the site']);
    assert.deepEqual(sourceProblems('x.md', '# Title\n\n---\n'), []);
  });

  test('invalid descriptors, colliding site paths and a non-empty build dir fail', () => {
    assert.throws(() => stage(PAGES, { descriptor: { ...RC, minor: '../2.1', repository: 'x' } }),
      /Invalid site descriptor field\(s\): minor, repository/);
    assert.throws(() => stage({ 'CHANGELOG.md': '# a\n', 'docs/guide/changelog.md': '# b\n' }),
      /CHANGELOG\.md and docs\/guide\/changelog\.md map to the same site page changelog\.md/);
    const { out } = stage({ 'CHANGELOG.md': '# a\n' });
    assert.throws(() => stageContent({ source: out, out, descriptor: RC, files: [] }), /is not empty/);
  });

  test('the git listing refuses a directory below the checkout root', () => {
    assert.throws(() => listRepoFiles(join(repoRoot, 'docs')), /is not the root of a git checkout/);
  });
});
