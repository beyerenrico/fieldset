// The built root (plan U4, R4, R5, R8, R11; AE4): stage the working tree's spec and root pages as the release
// v2.1.0-rc.1 with an rc-only versions.json, build it with VitePress and read the output: the byte-identical
// meta-schema, the spec pages and their links, the home page's install command and links, /fieldset/latest/ without
// JavaScript and the 404 page's static fallback. A dead link would fail the build itself.
import { after, before, describe, test } from 'node:test';
import assert from 'node:assert/strict';
import { readdirSync, readFileSync } from 'node:fs';
import { join, relative } from 'node:path';
import { fileURLToPath } from 'node:url';
import { buildRoot } from '../root/.vitepress/config.mjs';
import { FALLBACK_HTML } from '../lib/root-fallback.mjs';
import { stageRoot } from '../lib/root-stage.mjs';
import { decide } from '../lib/versions.mjs';
import { anchors, cleanup, RC, tempDir } from './support/site.mjs';

const repoRoot = fileURLToPath(new URL('../../../', import.meta.url));
const SPEC = 'spec/form-definition/1.0';
// Root-owned files plus the spec directory ("Assembled site and ownership"); nothing else may come out of the build.
const OWNED = new RegExp(['index\\.html', '404\\.html', 'hashmap\\.json', 'assets/[\\w.@-]+(/[\\w.@-]+)?', 'latest/index\\.html',
  'screenshots/[\\w.-]+', 'spec/form-definition/1\\.0/[\\w.-]+'].map((path) => `^${path}$`).join('|'));
after(cleanup);

let stageDir;
let outDir;
const page = (path) => readFileSync(join(outDir, path), 'utf8');
const linkTo = (html, text) => anchors(html).find((link) => link.text.replace(/<[^>]+>/g, '') === text);

before(async () => {
  stageDir = join(tempDir(), 'stage');
  outDir = join(tempDir(), 'out');
  const versions = decide(null, { tag: RC.tag, builtWith: 'a'.repeat(40) }).versions;
  stageRoot({ source: repoRoot, out: stageDir, descriptor: RC, versions });
  await buildRoot({ stageDir, outDir });
});

describe('the built root', () => {
  test('writes only root-owned files and the spec directory', () => {
    const files = readdirSync(outDir, { recursive: true, withFileTypes: true }).filter((entry) => !entry.isDirectory())
      .map((entry) => relative(outDir, join(entry.parentPath, entry.name)).split('\\').join('/'));
    assert.deepEqual(files.filter((file) => !OWNED.test(file)), []);
    for (const file of ['index.html', '404.html', 'latest/index.html', `${SPEC}/index.html`, `${SPEC}/changelog.html`]) {
      assert.ok(files.includes(file), file);
    }
    assert.ok(!files.includes('versions.json'));
  });

  test('serves the meta-schema byte-identical to the staged source (AE4), and every spec JSON file', () => {
    for (const name of ['meta-schema.json', 'roles.json']) {
      const built = readFileSync(join(outDir, SPEC, name));
      assert.ok(built.equals(readFileSync(join(stageDir, 'public', SPEC, name))), name);
      assert.ok(built.equals(readFileSync(join(repoRoot, SPEC, name))), name);
    }
  });

  test('renders the spec README at /fieldset/spec/form-definition/1.0/ with its links resolved', () => {
    const html = page(`${SPEC}/index.html`);
    assert.match(html, /<h1 id="fieldset-spec-10"/);
    const { href, target } = linkTo(html, 'meta-schema.json');
    assert.deepEqual({ href, target }, { href: 'meta-schema.json', target: '_self' });
    assert.equal(linkTo(html, 'roles.json').href, 'roles.json');
    assert.equal(linkTo(html, 'CHANGELOG.md').href, `/fieldset/${SPEC}/changelog`);
    assert.equal(linkTo(html, '../../conformance/').href, `https://github.com/beyerenrico/fieldset/tree/${RC.tag}/spec/conformance`);
  });

  test('renders the spec changelog at /fieldset/spec/form-definition/1.0/changelog', () => {
    assert.match(page(`${SPEC}/changelog.html`), /<h1 id="changelog--fieldset-spec-10"[^>]*>Changelog — Fieldset Spec 1\.0/);
  });

  test('bakes the rc install command, labelled pre-release, and links into the latest copy on the home page', () => {
    const html = page('index.html');
    const command = 'curl --proto &#39;=https&#39; --tlsv1.2 -fsSL https://github.com/beyerenrico/fieldset/releases/download/'
      + 'v2.1.0-rc.1/install.sh | sh -s -- --version 2.1.0-rc.1 --org &quot;Example Ltd&quot; --owner-email owner@example.com';
    assert.ok(html.includes(`<code data-v-`) && html.includes(command), 'install command');
    assert.match(html, /Install Fieldset 2\.1\.0-rc\.1<!--\]--><span class="mark"[^>]*>pre-release<\/span>/);
    const links = Object.fromEntries(anchors(html).filter((link) => link.target === '_self')
      .map((link) => [link.text.replace(/<[^>]+>/g, '').replace(/\s+/g, ' ').trim(), link.href]));
    assert.equal(links['Read the guides'], '/fieldset/2.1/');
    assert.equal(links['Fieldset Spec'], `/fieldset/${SPEC}/`);
    assert.equal(links['The install guide'], '/fieldset/2.1/install');
    assert.equal(links['Field App API contractThe normative contract of /api/v1.'], '/fieldset/2.1/field-app-api-v1');
    assert.equal(links['ChangelogWhat changed in each version.'], '/fieldset/2.1/changelog');
    assert.equal(links['Fieldset Spec 1.0The open JSON format for form definitions and its meta-schema.'], `/fieldset/${SPEC}/`);
    assert.doesNotMatch(html, /fieldset\/fieldset\//);
  });

  test('/fieldset/latest/ carries a plain link to the latest copy for visitors without JavaScript', () => {
    const html = page('latest/index.html');
    assert.deepEqual(linkTo(html, 'Continue to the Fieldset 2.1 documentation'), {
      href: '/fieldset/2.1/', target: '_self', text: 'Continue to the Fieldset 2.1 documentation',
    });
  });

  test('404.html carries the plain fallback links outside #app, without JavaScript', () => {
    const html = page('404.html');
    const fallback = html.indexOf(FALLBACK_HTML);
    assert.ok(fallback > html.indexOf('<body>') && fallback < html.indexOf('<div id="app"></div>'));
    assert.deepEqual(anchors(FALLBACK_HTML).map((link) => link.href), ['/fieldset/latest/', '/fieldset/']);
    const style = /<style>\.fieldset-static-fallback\{[^<]*body:has\(#app>\*\) \.fieldset-static-fallback\{display:none\}<\/style>/;
    assert.match(html.slice(0, html.indexOf('</head>')), style);
    assert.match(html, /<title>Page not found \| Fieldset<\/title>/);
  });
});
