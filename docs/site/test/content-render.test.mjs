// U2 verification: every staged page of the working tree and of v2.1.0-rc.1 renders through the configured renderer
// with no unresolved link, internal links and fragments that resolve, no Vue compile error, and the tag's raw
// placeholders shown literally. The full VitePress build is U3's; this proves the content and the transforms.
import { after, describe, test } from 'node:test';
import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import { join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { copyBuild, readSite, stageContent } from '../lib/stage.mjs';
import {
  anchors, cleanup, RC, render, rendererFor, serverRender, tempDir, vueCompileErrors, writeTree,
} from './support/site.mjs';

const repoRoot = fileURLToPath(new URL('../../../', import.meta.url));
const RC_TAG = 'v2.1.0-rc.1';
after(cleanup);

const git = (...args) => spawnSync('git', ['-C', repoRoot, ...args], { maxBuffer: 1 << 26 });
const tagFetched = git('rev-parse', '--verify', '--quiet', `${RC_TAG}^{commit}`).status === 0;

function stageWorkingTree() {
  const out = join(tempDir(), 'stage');
  stageContent({ source: repoRoot, out, descriptor: { ...RC, minor: 'dev', tag: null, ref: 'c'.repeat(40) } });
  return readSite(out);
}

// Reads the tag's tree without a worktree; `git archive` would drop CHANGELOG.md (export-ignore).
function stageTag(tag) {
  const files = git('ls-tree', '-r', '-z', '--name-only', tag).stdout.toString().split('\0').filter(Boolean);
  const pages = files.filter((path) => copyBuild.pagePath(path) !== null);
  const contents = Object.fromEntries(pages.map((path) => [path, git('show', `${tag}:${path}`).stdout]));
  const source = writeTree(tempDir(), contents);
  const out = join(tempDir(), 'stage');
  stageContent({ source, out, descriptor: RC, files });
  return readSite(out);
}

async function renderAll(site) {
  const md = await rendererFor(site);
  const pages = new Map();
  for (const repoPath of [...site.files].filter((path) => site.pagePath(path) !== null)) {
    const { html, env } = await render(md, site, repoPath);
    const ids = new Set([...html.matchAll(/\sid="([^"]+)"/g)].map(([, id]) => id));
    pages.set(site.pagePath(repoPath).replace(/\.md$/, ''), { repoPath, html, env, ids });
  }
  return pages;
}

// Internal links as VitePress records them for its dead-link check: /install#mail, /archive/1.0-install, /.
function unresolvedLinks(pages) {
  const problems = [];
  for (const [page, { repoPath, env, html }] of pages) {
    const sameRaw = anchors(html).filter((link) => link.href.startsWith('#')).map((link) => `/${page}${link.href}`);
    for (const link of [...(env.links ?? []), ...sameRaw]) {
      const [path, fragment] = link.split('#');
      const target = pages.get(path === '/' ? 'index' : path.slice(1).replace(/\/$/, '/index'));
      if (!target) problems.push(`${repoPath}: ${link} is no page`);
      else if (fragment && !target.ids.has(decodeURIComponent(fragment))) {
        problems.push(`${repoPath}: ${link} has no anchor`);
      }
    }
  }
  return problems;
}

async function assertRendersAsText(pages) {
  for (const { repoPath, html } of pages.values()) {
    assert.match(html, /^<div v-pre>\n[\s\S]*<\/div>\n$/, repoPath);
    assert.deepEqual(vueCompileErrors(html, repoPath), [], repoPath);
    await serverRender(html);
  }
}

describe('the working tree', () => {
  test('every staged page renders with resolved links and anchors and compiles as Vue', async () => {
    const pages = await renderAll(stageWorkingTree());
    const expected = ['index', 'changelog', 'field-app-api-v1', 'archive/1.0-install'];
    for (const page of expected) assert.ok(pages.has(page), page);
    assert.deepEqual(unresolvedLinks(pages), []);
    await assertRendersAsText(pages);
  });
});

describe(`the ${RC_TAG} content`, { skip: !tagFetched && `tag ${RC_TAG} is not fetched` }, () => {
  let site;
  let pages;
  test('every staged page renders with resolved links and anchors and compiles as Vue', async () => {
    site = stageTag(RC_TAG);
    pages = await renderAll(site);
    assert.equal(pages.size, 17);
    assert.deepEqual(unresolvedLinks(pages), []);
    await assertRendersAsText(pages);
  });

  test('its raw placeholders and {{…}} tokens show literally', async () => {
    const visible = async (page) => serverRender(pages.get(page).html);
    assert.match(await visible('field-app-guide'), /Weiter mit &lt;organization&gt;/);
    assert.match(await visible('troubleshooting'), /&lt;provider&gt;/);
    assert.match(await visible('archive/1.0-troubleshooting'), /&lt;provider&gt;/);
    assert.match(await visible('field-app-api-v1'), /Continue with &lt;organization&gt;/);
    assert.match(await visible('admin-guide'), /<code>\{\{org\.name\}\}<\/code>/);
  });

  test('the wrapper changes nothing inside: each page renders as without it', async () => {
    const md = await rendererFor(site);
    for (const { repoPath, html } of pages.values()) {
      const source = git('show', `${RC_TAG}:${repoPath}`).stdout.toString();
      const bare = (await render(md, site, repoPath, source)).html;
      assert.equal(`<div v-pre>\n${bare}</div>\n`, html, repoPath);
    }
  });
});
