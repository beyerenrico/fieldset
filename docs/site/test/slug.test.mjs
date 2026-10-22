// GitHub-compatible anchors (plan U2, R13, R15): heading ids and link fragments use github-slugger's stateless slug,
// and repeated headings get GitHub's -1 suffix from markdown-it-anchor.
import { after, before, test } from 'node:test';
import assert from 'node:assert/strict';
import { slugify } from '../lib/slug.mjs';
import { wrapVPre } from '../lib/stage.mjs';
import { anchors, cleanup, render, rendererFor, stageFixture } from './support/site.mjs';

const GITHUB_SLUGS = [
  ['Adopt a 1.0 install', 'adopt-a-10-install'],
  ['1. Connect the identity provider', '1-connect-the-identity-provider'],
  ['REST API (/api/v1)', 'rest-api-apiv1'],
  ['2.1.0 — 2026-10-22 (release candidate v2.1.0-rc.1)', '210--2026-10-22-release-candidate-v210-rc1'],
  ['Größe der Felder ändern', 'größe-der-felder-ändern'],
];

let site;
let md;
before(async () => {
  site = stageFixture({ pages: { 'docs/guide/install.md': '# Install\n' } });
  md = await rendererFor(site);
});
after(cleanup);

const headingIds = (html) => [...html.matchAll(/<h\d id="([^"]*)"/g)].map(([, id]) => id);
const renderInstall = async (markdown) => (await render(md, site, 'docs/guide/install.md', wrapVPre(markdown))).html;

test('slugify matches GitHub for headings where VitePress differs', () => {
  for (const [heading, slug] of GITHUB_SLUGS) assert.equal(slugify(heading), slug, heading);
});

test('rendered headings carry the GitHub ids and permalinks, inline code included', async () => {
  const html = await renderInstall([
    '## Adopt a 1.0 install', '### 1. Connect the identity provider', '## REST API (/api/v1)',
    '## 2.1.0 — 2026-10-22 (release candidate `v2.1.0-rc.1`)', '## Größe der Felder ändern',
  ].join('\n\n'));
  assert.deepEqual(headingIds(html), GITHUB_SLUGS.map(([, slug]) => slug));
  assert.match(html, /<a class="header-anchor" href="#adopt-a-10-install"/);
});

test('two identical headings on one page get x and x-1', async () => {
  const html = await renderInstall('## Mail\n\ntext\n\n## Mail\n\n## Mail\n');
  assert.deepEqual(headingIds(html), ['mail', 'mail-1', 'mail-2']);
});

test('repeated fragment links keep their slug: the slugify has no state', async () => {
  const html = await renderInstall('[a](#adopt-a-10-install) [b](#adopt-a-10-install) [c](install.md#mail)\n');
  assert.deepEqual(anchors(html).map((link) => link.href),
    ['#adopt-a-10-install', '#adopt-a-10-install', '/fieldset/2.1/install#mail']);
});
