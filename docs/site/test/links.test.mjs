// Links on content pages (plan U2, R13, R14, R15): staged targets become internal pages, the root spec a page-relative
// link with target="_self", other repo paths GitHub URLs at the copy's ref, and a missing target fails the render.
import { after, before, describe, test } from 'node:test';
import assert from 'node:assert/strict';
import { wrapVPre } from '../lib/stage.mjs';
import { anchor, cleanup, RC, render, rendererFor, stageFixture } from './support/site.mjs';

const PAGES = {
  'docs/guide/index.md': '# Guides\n',
  'docs/guide/install.md': '# Install\n\n## Adopt a 1.0 install\n',
  'docs/guide/agent-guide.md': '# Agent guide\n',
  'docs/guide/configuration.md': '# Configuration\n\n## Mail\n',
  'docs/guide/archive/1.0-upgrade.md': '# Upgrade 1.0\n',
  'docs/api/field-app-v1.md': '# Field App API\n',
  'CHANGELOG.md': '# Changelog\n',
};
const OTHER_FILES = [
  'README.md', 'SECURITY.md', 'docs/adr/0001-record.md', 'docs/releases/1.0.0.md', 'docs/api/other.md',
  'spec/form-definition/1.0/README.md', 'spec/form-definition/1.0/CHANGELOG.md',
  'spec/form-definition/1.0/meta-schema.json', 'spec/conformance/cases.json',
];
const GITHUB = 'https://github.com/beyerenrico/fieldset';

let site;
let md;
before(async () => {
  site = stageFixture({ pages: PAGES, otherFiles: OTHER_FILES });
  md = await rendererFor(site);
});
after(cleanup);

const renderAs = async (repoPath, markdown) => (await render(md, site, repoPath, wrapVPre(markdown))).html;
// Where a browser goes from a page's clean URL, which shows that no base is doubled.
const landing = (pageUrl, href) => new URL(href, `https://beyerenrico.github.io${pageUrl}`).pathname;

describe('staged targets', () => {
  test('a guide linking another guide renders the copy page, checked by the dead-link list', async () => {
    const { html, env } = await render(md, site, 'docs/guide/install.md', wrapVPre('[agent](agent-guide.md)\n'));
    assert.deepEqual(anchor(html, 'agent'), { href: '/fieldset/2.1/agent-guide', target: undefined, text: 'agent' });
    assert.deepEqual(env.links, ['/agent-guide']);
  });

  test('the index links the changelog, the Field App API and archive pages', async () => {
    const html = await renderAs('docs/guide/index.md',
      '[log](../../CHANGELOG.md) [api](../api/field-app-v1.md) [old](archive/1.0-upgrade.md)\n');
    assert.equal(anchor(html, 'log').href, '/fieldset/2.1/changelog');
    assert.equal(anchor(html, 'api').href, '/fieldset/2.1/field-app-api-v1');
    assert.equal(anchor(html, 'old').href, '/fieldset/2.1/archive/1.0-upgrade');
  });

  test('archive pages link up to the guides and the guide index', async () => {
    const html = await renderAs('docs/guide/archive/1.0-upgrade.md', '[new](../install.md) [home](../index.md)\n');
    assert.equal(anchor(html, 'new').href, '/fieldset/2.1/install');
    assert.equal(anchor(html, 'home').href, '/fieldset/2.1/');
  });

  test('the changelog links a guide relative to the repo root', async () => {
    const html = await renderAs('CHANGELOG.md', '[adopt](docs/guide/install.md#adopt-a-10-install)\n');
    assert.equal(anchor(html, 'adopt').href, '/fieldset/2.1/install#adopt-a-10-install');
  });
});

describe('the root spec, another build', () => {
  const SPEC = '/fieldset/spec/form-definition/1.0/';

  test('from a top-level guide: page-relative, target _self, no doubled base', async () => {
    const html = await renderAs('docs/guide/index.md',
      '[spec](../../spec/form-definition/1.0/README.md) [log](../../spec/form-definition/1.0/CHANGELOG.md)\n');
    assert.deepEqual(anchor(html, 'spec'), { href: '../spec/form-definition/1.0/', target: '_self', text: 'spec' });
    assert.equal(landing('/fieldset/2.1/', anchor(html, 'spec').href), SPEC);
    assert.equal(landing('/fieldset/2.1/install', anchor(html, 'spec').href), SPEC);
    assert.equal(anchor(html, 'log').target, '_self');
    assert.equal(landing('/fieldset/2.1/', anchor(html, 'log').href), `${SPEC}changelog`);
  });

  test('from an archive page, and its directory and raw JSON', async () => {
    const html = await renderAs('docs/guide/archive/1.0-upgrade.md', [
      '[spec](../../../spec/form-definition/1.0/README.md#versioning)',
      '[dir](../../../spec/form-definition/1.0/) [schema](../../../spec/form-definition/1.0/meta-schema.json)\n',
    ].join(' '));
    const spec = anchor(html, 'spec');
    assert.deepEqual(spec, { href: '../../spec/form-definition/1.0/#versioning', target: '_self', text: 'spec' });
    assert.equal(landing('/fieldset/2.1/archive/1.0-upgrade', spec.href), SPEC);
    assert.equal(anchor(html, 'dir').href, '../../spec/form-definition/1.0/');
    assert.equal(landing('/fieldset/2.1/archive/1.0-upgrade', anchor(html, 'schema').href), `${SPEC}meta-schema.json`);
    assert.equal(anchor(html, 'schema').target, '_self');
  });
});

describe('repo paths that are not on the site', () => {
  test('a directory opens the GitHub tree, a file the blob, at the tag', async () => {
    const html = await renderAs('docs/guide/index.md',
      '[adr](../adr/) [sec](../../SECURITY.md) [root](../../) [abs](/SECURITY.md)\n');
    assert.equal(anchor(html, 'adr').href, `${GITHUB}/tree/v2.1.0-rc.1/docs/adr`);
    assert.equal(anchor(html, 'sec').href, `${GITHUB}/blob/v2.1.0-rc.1/SECURITY.md`);
    assert.equal(anchor(html, 'root').href, `${GITHUB}/tree/v2.1.0-rc.1`);
    assert.equal(anchor(html, 'abs').href, `${GITHUB}/blob/v2.1.0-rc.1/SECURITY.md`, 'a leading / is the repo root');
  });

  test('an archive page reaches docs/releases through ../../releases', async () => {
    const html = await renderAs('docs/guide/archive/1.0-upgrade.md', '[notes](../../releases/1.0.0.md)\n');
    assert.equal(anchor(html, 'notes').href, `${GITHUB}/blob/v2.1.0-rc.1/docs/releases/1.0.0.md`);
  });

  test('a working-tree build links GitHub at the commit', async () => {
    const commit = 'a'.repeat(40);
    const descriptor = { ...RC, minor: 'dev', tag: null, ref: commit };
    const dev = stageFixture({ pages: PAGES, otherFiles: OTHER_FILES, descriptor });
    const devMd = await rendererFor(dev);
    const markdown = wrapVPre('[sec](../../SECURITY.md) [i](install.md)\n');
    const { html } = await render(devMd, dev, 'docs/guide/index.md', markdown);
    assert.equal(anchor(html, 'sec').href, `${GITHUB}/blob/${commit}/SECURITY.md`);
    assert.equal(anchor(html, 'i').href, '/fieldset/dev/install');
    md = await rendererFor(site);
  });
});

describe('fragments and absolute URLs', () => {
  test('a fragment survives each kind of rewrite; a same-page anchor stays as written', async () => {
    const html = await renderAs('docs/guide/install.md',
      '[mail](configuration.md#mail) [here](#adopt-a-10-install) [sec](../../SECURITY.md#reporting)\n');
    assert.equal(anchor(html, 'mail').href, '/fieldset/2.1/configuration#mail');
    assert.equal(anchor(html, 'here').href, '#adopt-a-10-install');
    assert.equal(anchor(html, 'sec').href, `${GITHUB}/blob/v2.1.0-rc.1/SECURITY.md#reporting`);
  });

  test("the agent guide's blob/main guide links become internal; other absolute URLs stay", async () => {
    const html = await renderAs('docs/guide/agent-guide.md', [
      `[install](${GITHUB}/blob/main/docs/guide/install.md)`,
      `[copy](${GITHUB}/blob/main/docs/guide/configuration.md#mail)`,
      `[readme](${GITHUB}/blob/main/README.md) [script](${GITHUB}/releases/latest/download/install.sh)\n`,
    ].join(' '));
    assert.deepEqual(anchor(html, 'install'), { href: '/fieldset/2.1/install', target: undefined, text: 'install' });
    assert.equal(anchor(html, 'copy').href, '/fieldset/2.1/configuration#mail');
    assert.equal(anchor(html, 'readme').href, `${GITHUB}/blob/main/README.md`);
    assert.equal(anchor(html, 'script').href, `${GITHUB}/releases/latest/download/install.sh`);
  });
});

describe('unresolved links', () => {
  test('a relative link to a missing file fails naming source file, line and target', async () => {
    await assert.rejects(renderAs('docs/guide/install.md', '# Install\n\nSee [x](missing.md#y).\n'),
      /^Error: docs\/guide\/install\.md:3: link target "missing\.md#y" does not exist .*docs\/guide\/missing\.md/);
  });

  test('a link above the repository root fails', async () => {
    await assert.rejects(renderAs('docs/guide/install.md', '[x](../../../outside.md)\n'),
      /docs\/guide\/install\.md:1: link target "\.\.\/\.\.\/\.\.\/outside\.md" points outside the repository/);
  });

  test('a link to a file the tag does not have fails although the allowlist would stage it', async () => {
    await assert.rejects(renderAs('docs/guide/index.md', '[gone](removed.md)\n'), /"removed\.md" does not exist/);
  });
});
