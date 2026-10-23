// U12 verification on a real dev build of the working tree, assembled as U13 does: the root built by VitePress at the
// output's top, then the version copy at dev/ with its reference page from a fixture export (VitePress empties a
// build's outDir, so the root goes first). Every check passes, and a broken anchor seeded into the built pages fails.
// Skipped only when the build tooling (docs/site/node_modules or git) is not installed.
import { after, before, describe, test } from 'node:test';
import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import { existsSync, mkdtempSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { fileURLToPath } from 'node:url';

const siteDir = fileURLToPath(new URL('../', import.meta.url));
const repoRoot = fileURLToPath(new URL('../../../', import.meta.url));
const head = spawnSync('git', ['-C', repoRoot, 'rev-parse', 'HEAD'], { encoding: 'utf8' });
const missingTooling = ['vitepress', '@scalar/api-reference']
  .map((name) => join(siteDir, 'node_modules', name, 'package.json')).find((file) => !existsSync(file))
  ?? (head.status === 0 ? null : 'git');
const DESCRIPTOR = Object.freeze({
  minor: 'dev', version: '2.1.0-rc.1', tag: null, ref: head.stdout?.trim(), prerelease: true,
  repository: 'beyerenrico/fieldset',
});

let work;
let outDir;
let checks;
let options;
after(() => work && rmSync(work, { recursive: true, force: true }));

async function buildSite() {
  const [{ buildCopy }, { buildRoot }, { stageContent }, { SPEC_DIR, stageRoot }, openapi] = await Promise.all([
    import('../copy/.vitepress/config.mjs'), import('../root/.vitepress/config.mjs'), import('../lib/stage.mjs'),
    import('../lib/root-stage.mjs'), import('../lib/openapi.mjs')]);
  work = mkdtempSync(join(tmpdir(), 'fieldset-checks-build-'));
  const [copyStage, rootStage, exportFile] = ['copy-stage', 'root-stage', 'openapi.json']
    .map((name) => join(work, name));
  outDir = join(work, 'site');
  const paths = Object.fromEntries(openapi.REQUIRED_PATHS.map((path) => [path, {
    get: { summary: `GET ${path}`, responses: { 200: { description: 'OK' } } } }]));
  writeFileSync(exportFile, JSON.stringify({ openapi: '3.1.0', info: { title: 'Fieldset API v1', version: 'dev' },
    servers: [{ url: 'http://localhost:8080/api/v1' }], paths }));
  stageContent({ source: repoRoot, out: copyStage, descriptor: DESCRIPTOR });
  openapi.writeReferencePage({ stageDir: copyStage, exportFile });
  stageRoot({ source: repoRoot, out: rootStage, descriptor: DESCRIPTOR, versions: null });
  await buildRoot({ stageDir: rootStage, outDir });
  await buildCopy({ stageDir: copyStage, outDir: join(outDir, 'dev') });
  return join(repoRoot, SPEC_DIR);
}

const skip = missingTooling ? `${missingTooling} is not installed` : false;

describe('a dev build of the working tree', { skip }, () => {
  before(async () => {
    checks = await import('../lib/checks.mjs');
    const specSourceDir = await buildSite();
    options = { outDir, copy: 'dev', root: true, mode: 'dev', specSourceDir };
  });

  test('passes every post-build check', () => {
    const result = checks.runChecks(options);
    assert.deepEqual(result.failures, [], checks.formatFailures(result.failures));
    assert.deepEqual(result.skipped, []);
    const { counts } = result;
    assert.ok(counts.fragments > 100, `only ${counts.fragments} fragment links checked`);
    assert.deepEqual([counts.literals, counts.spec, counts.reference, counts.fallback], [6, 2, 1, 1]);
  });

  test('resolves a fragment link from the copy into the built root spec', () => {
    const file = join(outDir, 'dev/index.html');
    const html = readFileSync(file, 'utf8');
    const link = /<a href="\.\.\/spec\/form-definition\/1\.0\/"/.exec(html)?.[0];
    assert.ok(link, 'the guide index links the spec page relative to itself');
    const linked = (fragment) => html.replace(link, `<a href="../spec/form-definition/1.0/#${fragment}"`);
    writeFileSync(file, linked('fieldset-spec-10'));
    assert.deepEqual(checks.runChecks(options).failures, []);
    writeFileSync(file, linked('no-such-section'));
    assert.deepEqual(checks.runChecks(options).failures, [{ check: 'fragments', file: 'dev/index.html',
      detail: 'link "../spec/form-definition/1.0/#no-such-section": spec/form-definition/1.0/index.html has no element '
        + 'with id "no-such-section"' }]);
    writeFileSync(file, html);
  });

  test('fails on an anchor removed from a built page, naming every page that links to it', () => {
    const file = join(outDir, 'dev/configuration.html');
    const html = readFileSync(file, 'utf8');
    assert.match(html, /\sid="mail"/);
    writeFileSync(file, html.replace(/\sid="mail"/g, ' id="mail-renamed"'));
    const failures = checks.runChecks(options).failures;
    assert.ok(failures.length > 0);
    for (const failure of failures) {
      assert.equal(failure.check, 'fragments');
      assert.match(failure.detail, /#mail": dev\/configuration\.html has no element with id "mail"$/);
    }
    assert.ok(failures.some((failure) => failure.file === 'dev/admin-guide.html'), checks.formatFailures(failures));
    writeFileSync(file, html);
  });
});
