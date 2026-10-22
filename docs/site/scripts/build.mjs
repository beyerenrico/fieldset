#!/usr/bin/env node
// Build orchestrator of the docs site (plan U13, R9, R15, R18; HTD "Version decision", "Assembled site and
// ownership"). It stages a release's content (lib/stage.mjs, lib/root-stage.mjs), adds the API reference
// (lib/openapi.mjs), builds the version copy and, when due, the root (copy/ and root/ .vitepress/config.mjs; the root
// first, because VitePress empties its output dir), runs the post-build checks (lib/checks.mjs) and, for a publish,
// writes the artifact the store job (U7) verifies. Tooling (docs/site) is this checkout; content comes from --source.
//
// Usage, from docs/site after `npm ci`:
//   node scripts/build.mjs dev --out <dir> (--export <openapi.json> | --stub-export) [--versions <versions.json>]
//   node scripts/build.mjs publish --source <dir> --tag <vX.Y.Z[-rc.N]> --out <dir> --export <openapi.json>
//       --stored <versions.json|none> --built-with <40-hex sha> --release-json <file>
//   node scripts/build.mjs check --source <dir> --tag <vX.Y.Z[-rc.N]> --out <dir>
//
// dev      The working tree (the repo two levels above docs/site) as /fieldset/dev/, GitHub links at its HEAD commit,
//          and the root, baking --versions (a versions.json) or no release. --export is the working tree's
//          `scramble:export`; a stub document replaces it only with --stub-export (CI passes a real export).
//          Output: <out>/dev/, the root's files at <out>/, and <out>/versions.json when --versions is given.
// publish  A release. --source is a clean checkout of --tag (HEAD at the tag's commit, refs/tags/<tag> resolvable in
//          it); neither has a default, so a failed worktree step can never publish main's content. --release-json is
//          the output of `php artisan release:version <tag>` and must state the tag's version, prerelease and channel.
//          --built-with is the tooling commit (this checkout's HEAD). decide() (lib/versions.mjs) runs against
//          --stored, the docs-site branch's versions.json, or `none` before the first publish (a missing file is an
//          error, never a first publish). A skip writes only <out>/decision.json (allowedPaths []) and exits 0. A
//          publish builds the copy, the root when refreshRoot, runs every check and writes, by ownership class:
//            <out>/site/<minor>/                     copy-owned
//            <out>/site/index.html, 404.html, hashmap.json, assets/, latest/, screenshots/
//                                                    root-owned, only when refreshRoot
//            <out>/site/spec/form-definition/1.0/    spec files, only when refreshRoot
//            <out>/site/versions.json                always
//            <out>/decision.json                     { action, reason, tag, minor, version, refreshRoot, allowedPaths }
//          allowedPaths lists the top-level entries (exact files, `dir/` prefixes) the store may change; the store
//          re-derives it from the verified tag and never trusts this file. decision.json is written last: a failed
//          build or check removes <out>/site and writes no decision.json.
// check    Content only (CI drift guard): --source is a clean checkout of --tag, built with a stub export and a
//          versions.json naming only this copy; no decision, no artifact; the checks skip only the reference checks.
//          Output: <out>/<minor>/ and the root's files at <out>/.
//
// --out must be missing or empty. Exit status: 0 on success or a skip, 1 on a failure, 2 on a usage error. Messages
// name files, tags and fields, never the environment.
import { spawnSync } from 'node:child_process';
import {
  copyFileSync, cpSync, lstatSync, mkdirSync, mkdtempSync, readdirSync, readFileSync, realpathSync, rmSync, statSync,
  writeFileSync,
} from 'node:fs';
import { tmpdir } from 'node:os';
import { join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { parseArgs } from 'node:util';
import { assertChecks } from '../lib/checks.mjs';
import { prepareDocument, readExport, REQUIRED_PATHS, writeReferencePage } from '../lib/openapi.mjs';
import { rootVersions, SPEC_DIR, stageRoot } from '../lib/root-stage.mjs';
import { stageContent } from '../lib/stage.mjs';
import { bakedVersions } from '../lib/theme-versions.mjs';
import { parseTag } from '../lib/versions-reader.mjs';
import { decide, serialize } from '../lib/versions.mjs';

export const REPOSITORY = 'beyerenrico/fieldset';
const SITE_DIR = fileURLToPath(new URL('../', import.meta.url));
const REPO_ROOT = fileURLToPath(new URL('../../../', import.meta.url));
const SHA = /^[0-9a-f]{40}$/;
const EXIT = Object.freeze({ ok: 0, failure: 1, usage: 2 });

export const USAGE = `Usage:
  node scripts/build.mjs dev --out <dir> (--export <openapi.json> | --stub-export) [--versions <versions.json>]
  node scripts/build.mjs publish --source <dir> --tag <vX.Y.Z[-rc.N]> --out <dir> --export <openapi.json>
      --stored <versions.json|none> --built-with <40-hex sha> --release-json <file>
  node scripts/build.mjs check --source <dir> --tag <vX.Y.Z[-rc.N]> --out <dir>`;

// Wrong or missing arguments: exit 2 with the usage.
export class UsageError extends Error {}

const OPTIONS = Object.freeze({
  out: 'string', export: 'string', versions: 'string', 'stub-export': 'boolean', source: 'string', tag: 'string',
  stored: 'string', 'built-with': 'string', 'release-json': 'string', help: 'boolean',
});
const MODES = Object.freeze({
  dev: { required: ['out'], optional: ['export', 'stub-export', 'versions'] },
  publish: { required: ['source', 'tag', 'out', 'export', 'stored', 'built-with', 'release-json'], optional: [] },
  check: { required: ['source', 'tag', 'out'], optional: [] },
});

const flags = (names) => names.map((name) => `--${name}`).join(', ');

function parsed(argv) {
  try {
    const options = Object.fromEntries(Object.entries(OPTIONS).map(([name, type]) => [name, { type }]));
    return parseArgs({ args: argv, options, allowPositionals: true, strict: true, tokens: true });
  } catch (error) {
    throw new UsageError(error.message);
  }
}

// { mode, options } of a command line; every check happens here, before anything is read or staged.
export function parseCli(argv) {
  const { values, positionals, tokens } = parsed(argv);
  if (values.help) return { mode: 'help', options: {} };
  const names = tokens.filter((token) => token.kind === 'option').map((token) => token.name);
  const repeated = [...new Set(names.filter((name, index) => names.indexOf(name) !== index))];
  if (repeated.length) throw new UsageError(`${flags(repeated)} given more than once`);
  const [mode, ...extra] = positionals;
  if (!Object.hasOwn(MODES, mode ?? '') || extra.length) {
    throw new UsageError(`Expected one mode, ${Object.keys(MODES).join(', ')}; got ${JSON.stringify(positionals)}`);
  }
  const { required, optional } = MODES[mode];
  const foreign = Object.keys(values).filter((name) => !required.includes(name) && !optional.includes(name));
  if (foreign.length) throw new UsageError(`${mode} does not take ${flags(foreign)}`);
  const empty = Object.keys(values).filter((name) => values[name] === '');
  if (empty.length) throw new UsageError(`${flags(empty)} needs a value`);
  const missing = required.filter((name) => values[name] === undefined);
  if (missing.length) throw new UsageError(`${mode} requires ${flags(missing)} (no defaults)`);
  if (mode === 'dev' && (values.export === undefined) === (values['stub-export'] === undefined)) {
    throw new UsageError('dev needs exactly one of --export <openapi.json> and --stub-export');
  }
  return { mode, options: values };
}

const log = (message) => process.stdout.write(`[docs build] ${message}\n`);
const fail = (message) => { throw new Error(message); };
const shown = (value) => {
  const text = JSON.stringify(value) ?? 'missing';
  return text.length > 60 ? `${text.slice(0, 57)}...` : text;
};

function readJson(file, what) {
  let text;
  try {
    text = readFileSync(file, 'utf8');
  } catch (error) {
    fail(`${what} ${file} cannot be read (${error.code ?? error.message})`);
  }
  try {
    return JSON.parse(text);
  } catch {
    return fail(`${what} ${file} is not valid JSON`);
  }
}

// What a release tag states, in the terms of decide() and `php artisan release:version`.
export function releaseFacts(tag) {
  const parsedTag = parseTag(tag);
  if (!parsedTag) throw new UsageError(`--tag ${shown(tag)} is not a release tag (vX.Y.Z or vX.Y.Z-alpha|beta|rc.N)`);
  const { core: [major, minor], prerelease } = parsedTag;
  return {
    tag, version: tag.slice(1), minor: `${major}.${minor}`, prerelease: prerelease !== null,
    channel: prerelease?.[0] ?? 'stable',
  };
}

// release:version prints { version, tag, channel, prerelease, image_tags }; the first four must be the tag's.
export function checkReleaseJson(json, facts) {
  if (json === null || typeof json !== 'object' || Array.isArray(json)) {
    fail('--release-json is not a JSON object (expected the output of php artisan release:version)');
  }
  const wrong = ['version', 'tag', 'channel', 'prerelease'].filter((key) => json[key] !== facts[key])
    .map((key) => `${key} is ${shown(json[key])}, expected ${shown(facts[key])}`);
  if (wrong.length) fail(`--release-json does not describe ${facts.tag}: ${wrong.join('; ')}`);
}

function git(cwd, ...args) {
  const result = spawnSync('git', ['-C', cwd, ...args], { encoding: 'utf8' });
  return result.status === 0 ? result.stdout.trim() : null;
}

// A release is built only from a clean checkout of its own tag.
function checkSource(source, tag) {
  if (!statSync(source, { throwIfNoEntry: false })?.isDirectory()) fail(`--source ${source} is not a directory`);
  if (git(source, 'rev-parse', '--show-prefix') !== '') fail(`--source ${source} is not the root of a git checkout`);
  const tagged = git(source, 'rev-parse', '--verify', '--quiet', `refs/tags/${tag}^{commit}`);
  if (!tagged) fail(`--source ${source} does not have the tag ${tag}`);
  const head = git(source, 'rev-parse', '--verify', 'HEAD^{commit}');
  if (head !== tagged) fail(`--source ${source} is at ${head}, not at ${tag} (${tagged})`);
  const status = git(source, 'status', '--porcelain');
  if (status !== '') fail(`--source ${source} ${status === null ? 'has no readable git status' : 'has local changes'}`);
}

function checkBuiltWith(builtWith) {
  if (!SHA.test(builtWith)) throw new UsageError('--built-with must be a full 40-character commit SHA');
  const tooling = git(SITE_DIR, 'rev-parse', '--verify', 'HEAD^{commit}');
  if (builtWith !== tooling) fail(`--built-with ${builtWith} is not the tooling checkout's commit ${tooling}`);
}

function prepareOut(out) {
  const stat = lstatSync(out, { throwIfNoEntry: false });
  if (stat && !stat.isDirectory()) fail(`--out ${out} is not a directory`);
  if (stat && readdirSync(out).length) fail(`--out ${out} is not empty`);
  mkdirSync(out, { recursive: true });
  return resolve(out);
}

// A document with the paths lib/openapi.mjs requires, for builds whose reference page is not checked or published.
export function stubExport() {
  const paths = Object.fromEntries(REQUIRED_PATHS.map((path) => [path, {
    get: { summary: `GET ${path} (stub)`, responses: { 200: { description: 'OK' } } },
  }]));
  return {
    openapi: '3.1.0', info: { title: 'Fieldset API v1 (stub export)', version: 'stub' },
    servers: [{ url: 'http://localhost:8080/api/v1' }], paths,
  };
}

function writeStub(work) {
  const file = join(work, 'stub-openapi.json');
  writeFileSync(file, `${JSON.stringify(stubExport(), null, 2)}\n`);
  return file;
}

const checkedExport = (file) => {
  prepareDocument(readExport(file));
  return file;
};

// Ownership classes of the assembled site; the store (U7) re-derives them from the verified tag.
export const ROOT_OWNED = Object.freeze([
  'index.html', '404.html', 'hashmap.json', 'assets/', 'latest/', 'screenshots/',
]);
export const SPEC_OWNED = `${SPEC_DIR}/`;
export const VERSIONS_FILE = 'versions.json';

export const allowedPaths = ({ minor, refreshRoot }) => [
  `${minor}/`, VERSIONS_FILE, ...(refreshRoot ? [...ROOT_OWNED, SPEC_OWNED] : []),
];

export function decisionRecord(decision, { tag, minor, version }) {
  const publish = decision.action === 'publish';
  const refreshRoot = publish && decision.refreshRoot === true;
  return {
    action: decision.action, reason: decision.reason, tag, minor, version, refreshRoot,
    allowedPaths: publish ? allowedPaths({ minor, refreshRoot }) : [],
  };
}

function filesBelow(dir, prefix) {
  return readdirSync(dir).flatMap((name) => {
    const stat = lstatSync(join(dir, name));
    return stat.isDirectory() ? filesBelow(join(dir, name), `${prefix}${name}/`) : [`${prefix}${name}`];
  });
}

// Why a top-level entry of the build output may not leave the build, or null when an ownership class holds it.
function entryProblem(built, name, { minor, root }) {
  const isDir = lstatSync(join(built, name)).isDirectory();
  if (name === minor) return isDir ? null : 'is not a directory';
  const owned = root ? ROOT_OWNED.find((entry) => entry.replace(/\/$/, '') === name) : undefined;
  if (owned) return owned.endsWith('/') === isDir ? null : `must be ${isDir ? 'a file' : 'a directory'}`;
  if (!root || name !== 'spec') return 'belongs to no ownership class of this build';
  if (!isDir) return 'is not a directory';
  const stray = filesBelow(join(built, name), 'spec/')
    .filter((file) => !file.startsWith(SPEC_OWNED) || file.slice(SPEC_OWNED.length).includes('/'));
  return stray.length ? `holds ${stray.join(', ')} outside ${SPEC_OWNED}` : null;
}

// Copies the build output into target by ownership class; an entry no class holds fails the build.
function assemble(built, target, scope) {
  const names = readdirSync(built).sort();
  const problems = names.map((name) => [name, entryProblem(built, name, scope)]).filter(([, problem]) => problem);
  if (problems.length) {
    fail(`The build wrote entries the site does not own:\n  ${problems.map((entry) => entry.join(' ')).join('\n  ')}`);
  }
  mkdirSync(target, { recursive: true });
  names.forEach((name) => cpSync(join(built, name), join(target, name), { recursive: true }));
}

// Stages everything first, so content problems fail before the slow builds; then the root, then the copy below it.
async function buildSite({ source, descriptor, versions, exportFile, work, root }) {
  const [copyStage, rootStage, built] = ['copy-stage', 'root-stage', 'build'].map((name) => join(work, name));
  log(`Staging ${descriptor.tag ?? `the working tree at ${descriptor.ref}`} from ${source}`);
  stageContent({ source, out: copyStage, descriptor });
  const reference = writeReferencePage({ stageDir: copyStage, exportFile });
  log(`API reference with ${reference.paths.length} paths, Scalar bundle sha256 ${reference.sha256}`);
  if (root) stageRoot({ source, out: rootStage, descriptor, versions });
  mkdirSync(built);
  if (root) {
    log('Building the root at /fieldset/');
    const { buildRoot } = await import('../root/.vitepress/config.mjs');
    await buildRoot({ stageDir: rootStage, outDir: built });
  }
  log(`Building the copy at /fieldset/${descriptor.minor}/`);
  const { buildCopy } = await import('../copy/.vitepress/config.mjs');
  await buildCopy({ stageDir: copyStage, outDir: join(built, descriptor.minor) });
  return built;
}

function check({ target, minor, root, mode, source }) {
  const result = assertChecks({ outDir: target, copy: minor, root, mode, specSourceDir: join(source, SPEC_DIR) });
  const counts = Object.entries(result.counts).map(([name, count]) => `${name} ${count}`).join(', ');
  const skipped = result.skipped.length ? `; not run: ${result.skipped.join(', ')}` : '';
  log(`Post-build checks passed (${counts})${skipped}`);
}

const releaseDescriptor = ({ minor, version, tag, prerelease }) => ({
  minor, version, tag, prerelease, repository: REPOSITORY,
});

async function runDev(options, work) {
  const exportFile = options.export === undefined ? writeStub(work) : checkedExport(options.export);
  const versions = options.versions === undefined ? null : readJson(options.versions, '--versions');
  try {
    rootVersions(versions);
  } catch (error) {
    fail(`--versions ${options.versions}: ${error.message}`);
  }
  const ref = git(REPO_ROOT, 'rev-parse', '--verify', 'HEAD^{commit}') ?? fail(`${REPO_ROOT} has no HEAD commit`);
  const out = prepareOut(options.out);
  const descriptor = { minor: 'dev', version: 'dev', tag: null, ref, prerelease: true, repository: REPOSITORY };
  const built = await buildSite({ source: REPO_ROOT, descriptor, versions, exportFile, work, root: true });
  assemble(built, out, { minor: 'dev', root: true });
  if (versions !== null) copyFileSync(options.versions, join(out, VERSIONS_FILE));
  check({ target: out, minor: 'dev', root: true, mode: 'dev', source: REPO_ROOT });
  log(`Built the working tree into ${out}`);
}

async function runCheck(options, work) {
  const facts = releaseFacts(options.tag);
  checkSource(options.source, facts.tag);
  const out = prepareOut(options.out);
  const descriptor = releaseDescriptor(facts);
  const built = await buildSite({
    source: options.source, descriptor, versions: bakedVersions(descriptor), exportFile: writeStub(work), work,
    root: true,
  });
  assemble(built, out, { minor: facts.minor, root: true });
  check({ target: out, minor: facts.minor, root: true, mode: 'check', source: options.source });
  log(`Checked ${facts.tag} with the current tooling into ${out}`);
}

function writeDecision(out, record) {
  writeFileSync(join(out, 'decision.json'), `${JSON.stringify(record, null, 2)}\n`);
}

async function runPublish(options, work) {
  const facts = releaseFacts(options.tag);
  checkReleaseJson(readJson(options['release-json'], '--release-json'), facts);
  checkBuiltWith(options['built-with']);
  checkSource(options.source, facts.tag);
  checkedExport(options.export);
  const stored = options.stored === 'none' ? null : readJson(options.stored, '--stored');
  const decision = decide(stored, { ...facts, builtWith: options['built-with'] });
  const out = prepareOut(options.out);
  const record = decisionRecord(decision, facts);
  if (decision.action === 'skip') {
    writeDecision(out, record);
    log(`Skip ${facts.tag}: ${decision.reason}`);
    return;
  }
  log(`Publish ${facts.tag} as /fieldset/${facts.minor}/: ${decision.reason}`
    + `${record.refreshRoot ? '; the root refreshes' : ''}`);
  const target = join(out, 'site');
  try {
    const built = await buildSite({
      source: options.source, descriptor: releaseDescriptor(facts), versions: decision.versions,
      exportFile: options.export, work, root: record.refreshRoot,
    });
    assemble(built, target, { minor: facts.minor, root: record.refreshRoot });
    writeFileSync(join(target, VERSIONS_FILE), serialize(decision.versions));
    check({ target, minor: facts.minor, root: record.refreshRoot, mode: 'publish', source: options.source });
  } catch (error) {
    rmSync(target, { recursive: true, force: true }); // no partial artifact
    throw error;
  }
  writeDecision(out, record);
  log(`Wrote the artifact for ${facts.tag} into ${out}: ${record.allowedPaths.join(' ')}`);
}

const RUNNERS = Object.freeze({ dev: runDev, publish: runPublish, check: runCheck });

// Runs one command line; resolves to the exit status and never throws.
export async function main(argv) {
  let work = null;
  try {
    const { mode, options } = parseCli(argv);
    if (mode === 'help') {
      process.stdout.write(`${USAGE}\n`);
      return EXIT.ok;
    }
    work = mkdtempSync(join(tmpdir(), 'fieldset-docs-work-'));
    await RUNNERS[mode](options, work);
    return EXIT.ok;
  } catch (error) {
    process.stderr.write(`build.mjs: ${error?.message ?? String(error)}\n`);
    if (!(error instanceof UsageError)) return EXIT.failure;
    process.stderr.write(`${USAGE}\n`);
    return EXIT.usage;
  } finally {
    if (work) rmSync(work, { recursive: true, force: true });
  }
}

// Runs only as `node scripts/build.mjs …`; tests import the exports. The exit status is set, not forced, so the
// output is flushed (stdout is asynchronous on macOS pipes).
function invokedAsScript() {
  try {
    return realpathSync(process.argv[1]) === realpathSync(fileURLToPath(import.meta.url));
  } catch {
    return false;
  }
}
if (invokedAsScript()) process.exitCode = await main(process.argv.slice(2));
