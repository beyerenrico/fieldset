// The OpenAPI reference page (plan U11, R2, R22): the export is checked and its servers become a {server} variable;
// the page carries the KTD's CSP literal, embeds the document so that no string in it can end the data block, passes
// Scalar's options as JSON in an attribute, runs no inline script and loads the bundle copied from node_modules.
import { after, before, describe, test } from 'node:test';
import assert from 'node:assert/strict';
import { createHash } from 'node:crypto';
import { existsSync, readFileSync, writeFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import { join } from 'node:path';
import { fileURLToPath } from 'node:url';
import {
  prepareDocument, REFERENCE_CSP, REFERENCE_PAGE, REQUIRED_PATHS, SCALAR_BUNDLE, SCALAR_OPTIONS, scalarBundle,
  writeReferencePage,
} from '../lib/openapi.mjs';
import { cleanup, stageFixture, tempDir } from './support/site.mjs';

const siteDir = fileURLToPath(new URL('../', import.meta.url));
const repoRoot = fileURLToPath(new URL('../../../', import.meta.url));
const require = createRequire(join(siteDir, 'package.json'));
const KTD_CSP = "default-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; connect-src 'none'; "
  + "form-action 'none'";
const HOSTILE = '</script><script>alert(1)</script>';
after(cleanup);

const operation = (description = 'OK') => ({ get: { description, responses: { 200: { description: 'OK' } } } });
function exported(overrides = {}) {
  return {
    openapi: '3.1.0',
    info: { title: 'Fieldset API v1', version: '2.1.0-rc.1' },
    servers: [{ url: 'http://localhost:8080/api/v1' }],
    paths: Object.fromEntries(REQUIRED_PATHS.map((path) => [path, operation()])),
    components: { responses: {} },
    ...overrides,
  };
}

const sha256 = (bytes, encoding = 'hex') => createHash('sha256').update(bytes).digest(encoding);
const decodeEntities = (text) => text.replace(/&(quot|#39|lt|gt|amp);/g, (_, name) => ({
  quot: '"', '#39': "'", lt: '<', gt: '>', amp: '&',
})[name]);
const scriptTags = (html) => [...html.matchAll(/<script\b([^>]*)>([\s\S]*?)<\/script>/gi)]
  .map(([, attributes, body]) => ({ attributes, body }));
const attribute = (attributes, name) => {
  const value = new RegExp(`\\s${name}="([^"]*)"`).exec(attributes)?.[1];
  return value === undefined ? undefined : decodeEntities(value);
};

describe('prepareDocument', () => {
  test('rewrites an export with servers http://localhost:8080/api/v1 to the {server} variable', () => {
    const input = exported();
    const snapshot = structuredClone(input);
    const document = prepareDocument(input);
    assert.deepEqual(document.servers, [{
      url: 'https://{server}/api/v1',
      variables: { server: { default: 'fieldset.example.com', description: 'Host name of your Fieldset instance' } },
    }]);
    assert.deepEqual({ ...document, servers: snapshot.servers }, snapshot);
    assert.deepEqual(input, snapshot, 'the export is not modified');
  });

  test('rejects an OpenAPI 3.0 document', () => {
    assert.throws(() => prepareDocument(exported({ openapi: '3.0.3' })), /openapi is "3\.0\.3", expected 3\.1\.x/);
  });

  test('rejects an export without /forms', () => {
    const { '/forms': _, ...paths } = exported().paths;
    assert.throws(() => prepareDocument(exported({ paths })), /missing path\(s\): \/forms$/m);
  });

  test('rejects servers the paths are not relative to, and servers below the document level', () => {
    assert.throws(() => prepareDocument(exported({ servers: [{ url: 'http://localhost:8080' }] })),
      /does not end in \/api\/v1/);
    assert.throws(() => prepareDocument(exported({ servers: [] })), /servers is missing/);
    const paths = { ...exported().paths, '/tasks': { servers: [], get: { servers: [{ url: 'https://evil.example' }] } } };
    assert.throws(() => prepareDocument(exported({ paths })),
      /paths\["\/tasks"\] declares its own servers\n {2}GET \/tasks declares its own servers/);
  });

  test('rejects a document that is not an OpenAPI object', () => {
    for (const input of [null, [], 'openapi: 3.1.0', exported({ info: undefined }), exported({ paths: undefined })]) {
      assert.throws(() => prepareDocument(input), /The OpenAPI export is not usable/);
    }
  });

  test('expects the paths that tests/Feature/Api/OpenApiDocsTest.php expects', () => {
    const phpTest = readFileSync(join(repoRoot, 'tests/Feature/Api/OpenApiDocsTest.php'), 'utf8');
    const listed = /->toContain\(([^)]*)\)/.exec(phpTest)[1];
    assert.deepEqual([...listed.matchAll(/'([^']+)'/g)].map(([, path]) => path), [...REQUIRED_PATHS]);
  });
});

describe('the reference page', () => {
  let site;
  let result;
  let html;
  let hostile;

  before(() => {
    site = stageFixture({ pages: { 'docs/guide/index.md': '# Guides\n' } });
    hostile = exported({
      info: { title: `Fieldset ${HOSTILE}`, version: '2.1.0-rc.1', description: `<!-- ${HOSTILE} --> & </SCRIPT >` },
      paths: { ...exported().paths, [`/x${HOSTILE}`]: operation(`<!--<script>${HOSTILE}`) },
    });
    const exportFile = join(tempDir(), 'openapi.json');
    writeFileSync(exportFile, JSON.stringify(hostile, null, 4));
    result = writeReferencePage({ stageDir: site.srcDir, exportFile });
    html = readFileSync(join(site.srcDir, 'public', REFERENCE_PAGE), 'utf8');
  });

  test('is written with the bundle into the staged dir\'s public/, VitePress\'s public dir of the copy', () => {
    assert.equal(result.page, join(site.srcDir, 'public', REFERENCE_PAGE));
    assert.equal(result.bundle, join(site.srcDir, 'public', SCALAR_BUNDLE));
    assert.deepEqual(result.paths, Object.keys(hostile.paths));
    assert.match(html, /^<!doctype html>\n<html lang="en">/);
    assert.match(html, /<title>API reference \| Fieldset 2\.1<\/title>/);
  });

  test('carries the CSP of the KTD, as the raw literal, before any script or style', () => {
    assert.equal(REFERENCE_CSP, KTD_CSP);
    const metas = [...html.matchAll(/<meta\s+http-equiv="Content-Security-Policy"\s+content="([^"]*)">/gi)];
    assert.equal(metas.length, 1);
    assert.equal(metas[0][1], KTD_CSP);
    assert.ok(html.includes(`content="${KTD_CSP}"`), 'the literal is greppable');
    const first = Math.min(...['<script', '<style', '<link'].map((tag) => html.indexOf(tag)).filter((at) => at >= 0));
    assert.ok(metas[0].index < first);
    assert.match(html, /<head>\n<meta charset="utf-8">\n<meta http-equiv="Content-Security-Policy"/);
  });

  test('loads the bundle copied byte for byte from the installed package, pinned by its sha256', () => {
    const installedFile = join(siteDir, 'node_modules/@scalar/api-reference/dist/browser/standalone.js');
    const installed = readFileSync(installedFile);
    const copied = readFileSync(result.bundle);
    assert.ok(copied.equals(installed));
    assert.equal(result.sha256, sha256(installed));
    assert.equal(scalarBundle().sha256, sha256(installed));
    const pinned = JSON.parse(readFileSync(join(siteDir, 'package.json'), 'utf8')).devDependencies['@scalar/api-reference'];
    assert.equal(scalarBundle().version, pinned);
    const bundleTag = scriptTags(html).find(({ attributes }) => attribute(attributes, 'src') !== undefined);
    assert.equal(attribute(bundleTag.attributes, 'src'), SCALAR_BUNDLE);
    assert.equal(attribute(bundleTag.attributes, 'integrity'), `sha256-${sha256(installed, 'base64')}`);
  });

  test('embeds the document so that hostile strings cannot end the data block or start a comment', () => {
    assert.equal(html.match(/<\/script/gi).length, 2, 'only the two real closing tags');
    assert.equal(html.match(/<script/gi).length, 2, 'no injected opening tag');
    assert.ok(!html.includes('<!--'));
    const [data] = scriptTags(html);
    assert.equal(attribute(data.attributes, 'id'), 'api-reference');
    assert.equal(attribute(data.attributes, 'type'), 'application/json');
    assert.ok(!/[<>&]/.test(data.body), 'the JSON text holds no < > &');
    assert.deepEqual(JSON.parse(data.body), prepareDocument(hostile), 'the hostile strings survive as data');
    assert.equal(JSON.parse(data.body).info.title, `Fieldset ${HOSTILE}`);
  });

  test('passes Scalar\'s options as JSON in data-configuration, read the way Scalar\'s HTML API reads them', () => {
    const [data] = scriptTags(html);
    // Scalar: JSON.parse(element.getAttribute('data-configuration').split('&quot;').join('"'))
    const read = JSON.parse(attribute(data.attributes, 'data-configuration').split('&quot;').join('"'));
    assert.deepEqual(read, SCALAR_OPTIONS);
    assert.deepEqual(read, {
      telemetry: false, withDefaultFonts: false, persistAuth: false, hideTestRequestButton: true,
      hideClientButton: true, showDeveloperTools: 'never', agent: { disabled: true }, mcp: { disabled: true },
    });
    for (const key of ['url', 'content', 'proxyUrl', 'proxy', 'pluginUrls', 'customFetch', 'fetch']) {
      assert.ok(!Object.hasOwn(read, key), key);
    }
  });

  test('uses option names the pinned Scalar version keeps', async () => {
    const scalar = JSON.parse(readFileSync(join(siteDir, 'node_modules/@scalar/api-reference/package.json'), 'utf8'));
    const schemas = JSON.parse(readFileSync(join(siteDir, 'node_modules/@scalar/schemas/package.json'), 'utf8'));
    assert.equal(schemas.version, scalar.dependencies['@scalar/schemas'], 'the schema the bundle was built with');
    const { apiReferenceConfigurationWithSourceSchema } = await import(require.resolve('@scalar/schemas/api-reference'));
    const coerced = apiReferenceConfigurationWithSourceSchema(structuredClone(SCALAR_OPTIONS));
    for (const [key, value] of Object.entries(SCALAR_OPTIONS)) assert.deepEqual(coerced[key], value, key);
  });

  test('runs no inline script and loads nothing from another origin', () => {
    for (const { attributes, body } of scriptTags(html)) {
      const external = attribute(attributes, 'src') !== undefined;
      assert.ok(external ? body === '' : attribute(attributes, 'type') === 'application/json', attributes);
    }
    assert.ok(!/\son\w+\s*=/i.test(html), 'no event handler attributes');
    assert.ok(!/<(style|link|iframe|object|embed|base|form)\b/i.test(html));
    const urls = [...html.matchAll(/\s(?:src|href)="([^"]*)"/g)].map(([, url]) => url);
    assert.deepEqual(urls, ['field-app-api-v1', SCALAR_BUNDLE]);
  });

  test('checks the export before writing anything', () => {
    const stage = stageFixture({ pages: { 'docs/guide/index.md': '# Guides\n' } });
    const dir = tempDir();
    writeFileSync(join(dir, 'broken.json'), '{"openapi":');
    writeFileSync(join(dir, 'v30.json'), JSON.stringify(exported({ openapi: '3.0.3' })));
    assert.throws(() => writeReferencePage({ stageDir: stage.srcDir, exportFile: join(dir, 'broken.json') }),
      /Cannot read the OpenAPI export .*broken\.json/);
    assert.throws(() => writeReferencePage({ stageDir: stage.srcDir, exportFile: join(dir, 'v30.json') }),
      /expected 3\.1\.x/);
    assert.ok(!existsSync(join(stage.srcDir, 'public')));
  });
});
