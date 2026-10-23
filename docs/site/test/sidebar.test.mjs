// The copy's sidebar follows the staged guide index (plan U3): its sections become groups, links to pages of the copy
// become items, and pages the index does not reach are still listed. Texts are HTML-escaped (VitePress renders sidebar
// texts with v-html).
import { after, describe, test } from 'node:test';
import assert from 'node:assert/strict';
import { join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { copySidebar } from '../lib/sidebar.mjs';
import { readSite, stageContent } from '../lib/stage.mjs';
import { cleanup, RC, stageFixture, tempDir } from './support/site.mjs';

const repoRoot = fileURLToPath(new URL('../../../', import.meta.url));
after(cleanup);

const links = (items) => items.flatMap((item) => [item.link, ...links(item.items ?? [])]).filter(Boolean);

describe('the working tree', () => {
  const out = join(tempDir(), 'stage');
  stageContent({ source: repoRoot, out, descriptor: { ...RC, minor: 'dev', tag: null, ref: 'c'.repeat(40) } });
  const site = readSite(out);
  const sidebar = copySidebar(site);

  test('follows the guide index sections in order, overview first', () => {
    assert.deepEqual(sidebar.map((item) => item.text), [
      'Overview', 'For operators', 'For organizations', 'For developers and integrators',
    ]);
    assert.equal(sidebar[0].link, '/');
  });

  test('lists the guides of each section and the archive as a collapsed group', () => {
    const operators = sidebar[1].items;
    assert.deepEqual(operators.map((item) => [item.text, item.link]), [
      ['Install', '/install'], ['Agent guide', '/agent-guide'], ['Upgrade', '/upgrade'],
      ['Backup and restore', '/backup-restore'], ['Configuration', '/configuration'],
      ['Troubleshooting', '/troubleshooting'], ['Archive: 1.0 checkout installs', undefined],
    ]);
    const archive = operators.at(-1);
    assert.equal(archive.collapsed, true);
    assert.deepEqual(archive.items.map((item) => [item.text, item.link]), [
      ['Install', '/archive/1.0-install'], ['Upgrade', '/archive/1.0-upgrade'],
      ['Backup and restore', '/archive/1.0-backup-restore'], ['Configuration', '/archive/1.0-configuration'],
      ['Troubleshooting', '/archive/1.0-troubleshooting'],
    ]);
  });

  test('keeps only pages of this copy: the spec, ADRs, README and policy stay on the index page', () => {
    assert.deepEqual(sidebar[3].items.map((item) => [item.text, item.link]), [
      ['API and integrations', '/api'], ['Field App API contract', '/field-app-api-v1'], ['Changelog', '/changelog'],
    ]);
    for (const link of links(sidebar)) assert.match(link, /^\/[\w./-]*$/);
  });

  test('every staged page is reachable', () => {
    const pages = [...site.files].filter((path) => site.pagePath(path) !== null);
    const reached = new Set(links(sidebar));
    for (const page of pages) {
      const link = `/${site.pagePath(page).replace(/\.md$/, '').replace(/(^|\/)index$/, '$1')}`;
      assert.ok(reached.has(link), `${page} (${link}) is not in the sidebar`);
    }
  });
});

describe('fixtures', () => {
  const index = (rows) => `# Docs\n\n## Guides\n\n| Guide | Contents |\n|---|---|\n${rows.join('\n')}\n`;

  test('a row linking further pages becomes a group that keeps its own link unless an item repeats it', () => {
    const site = stageFixture({
      pages: {
        'docs/guide/index.md': index([
          '| [Install](install.md) | See [upgrade](upgrade.md) |',
          '| [Archive](archive/a.md) | [a](archive/a.md), [b](archive/b.md) |',
        ]),
        'docs/guide/install.md': '# Install\n',
        'docs/guide/upgrade.md': '# Upgrade\n',
        'docs/guide/archive/a.md': '# A\n',
        'docs/guide/archive/b.md': '# B\n',
      },
    });
    assert.deepEqual(copySidebar(site)[1].items, [
      { text: 'Install', link: '/install', collapsed: true, items: [{ text: 'Upgrade', link: '/upgrade' }] },
      {
        text: 'Archive', collapsed: true,
        items: [{ text: 'A', link: '/archive/a' }, { text: 'B', link: '/archive/b' }],
      },
    ]);
  });

  test('a page the index does not link is listed under "More pages" with its title', () => {
    const site = stageFixture({
      pages: {
        'docs/guide/index.md': index(['| [Install](install.md) | How to install |']),
        'docs/guide/install.md': '# Install\n',
        'docs/guide/new-guide.md': '# A new guide\n\nText.\n',
      },
    });
    const sidebar = copySidebar(site);
    assert.deepEqual(sidebar.at(-1), {
      text: 'More pages', collapsed: false, items: [{ text: 'A new guide', link: '/new-guide' }],
    });
  });

  test('link texts are escaped and stripped of code spans; fragments are dropped', () => {
    const site = stageFixture({
      pages: {
        'docs/guide/index.md': index(['| [`fieldset` <b>CLI</b> \\[beta\\]](install.md#flags) | x |']),
        'docs/guide/install.md': '# Install\n',
      },
    });
    assert.deepEqual(copySidebar(site)[1].items, [{ text: 'fieldset &lt;b&gt;CLI&lt;/b&gt; [beta]', link: '/install' }]);
  });

  test('without a staged index every page is listed under "More pages"', () => {
    const site = stageFixture({ pages: { 'docs/guide/install.md': '# Install & run\n' } });
    assert.deepEqual(copySidebar(site), [
      { text: 'More pages', collapsed: false, items: [{ text: 'Install &amp; run', link: '/install' }] },
    ]);
  });

  test('a broken index link fails, naming the index line', () => {
    const site = stageFixture({ pages: { 'docs/guide/index.md': index(['| [Gone](gone.md) | x |']) } });
    assert.throws(() => copySidebar(site), /docs\/guide\/index\.md:7: link target "gone\.md" does not exist/);
  });
});
