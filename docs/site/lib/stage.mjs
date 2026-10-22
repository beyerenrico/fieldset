// Stages a release's content for one version copy (plan U2, R1, R3): copies the allowlisted Markdown from a source root
// (a worktree of the tag, or the working tree in dev mode) into a build dir under its repo path, wraps each page in a
// v-pre container so Vue never compiles it, and writes the site descriptor plus the repo file index the links rule
// resolves against. VitePress features that run before markdown-it are rejected here, naming file and line.
import { spawnSync } from 'node:child_process';
import { existsSync, mkdirSync, readdirSync, readFileSync, writeFileSync } from 'node:fs';
import { dirname, join, relative, resolve, sep } from 'node:path';

export const SITE_ROOT = '/fieldset/';
export const DESCRIPTOR_FILE = 'site.json';
export const REPO_FILES_FILE = 'repo-files.json';
const SPEC_DIR = 'spec/form-definition/1.0';

// Pages of a version copy and their site paths; config.mjs (U3) passes copyBuild.pagePath as VitePress `rewrites`.
// Targets served by another build (the root spec) get absolute URLs that the links rule turns into page-relative ones.
export const copyBuild = {
  base: (descriptor) => `${SITE_ROOT}${descriptor.minor}/`,
  pagePath(repoPath) {
    if (/^docs\/guide\/.+\.md$/.test(repoPath)) return repoPath.slice('docs/guide/'.length);
    if (repoPath === 'docs/api/field-app-v1.md') return 'field-app-api-v1.md';
    if (repoPath === 'CHANGELOG.md') return 'changelog.md';
    return null;
  },
  crossBuildUrl(repoPath) {
    if (repoPath === `${SPEC_DIR}/README.md`) return `${SITE_ROOT}${SPEC_DIR}/`;
    if (repoPath === `${SPEC_DIR}/CHANGELOG.md`) return `${SITE_ROOT}${SPEC_DIR}/changelog`;
    if (repoPath.startsWith(`${SPEC_DIR}/`) && /^[^/]+\.json$/.test(repoPath.slice(SPEC_DIR.length + 1))) {
      return `${SITE_ROOT}${repoPath}`;
    }
    return null;
  },
};

// The wrapper's marker is longer than any colon run in the page, so no line of the page can close it early.
const V_PRE_OPEN = /^(:{3,}) v-pre\n/;
export function wrapVPre(text) {
  const longest = text.split('\n').reduce((max, line) => Math.max(max, /^\s*(:*)/.exec(line)[1].length), 2);
  const marker = ':'.repeat(longest + 1);
  return `${marker} v-pre\n${text}${text.endsWith('\n') ? '' : '\n'}${marker}\n`;
}
// Lines the wrapper adds above the source, for messages that name the source line.
export const wrapperLines = (src) => (V_PRE_OPEN.test(src) ? 1 : 0);

const DESCRIPTOR_RULES = {
  minor: (v) => v === 'dev' || /^\d+\.\d+$/.test(v),
  version: (v) => typeof v === 'string' && /^[\w.+-]+$/.test(v),
  tag: (v) => v === null || /^v\d+\.\d+\.\d+(-[0-9A-Za-z.]+)?$/.test(v),
  ref: (v) => typeof v === 'string' && /^[\w][\w./-]*$/.test(v),
  prerelease: (v) => typeof v === 'boolean',
  repository: (v) => typeof v === 'string' && /^[\w.-]+\/[\w.-]+$/.test(v),
};

// tag is null for a working-tree build, whose blob links then point at ref (the commit SHA).
export function validateDescriptor(input) {
  const descriptor = { ...input, tag: input.tag ?? null, ref: input.ref ?? input.tag };
  const invalid = Object.entries(DESCRIPTOR_RULES).filter(([key, ok]) => !ok(descriptor[key])).map(([key]) => key);
  if (invalid.length) throw new Error(`Invalid site descriptor field(s): ${invalid.join(', ')}`);
  return Object.fromEntries(Object.keys(DESCRIPTOR_RULES).map((key) => [key, descriptor[key]]));
}

// Tracked plus untracked, non-ignored files that exist: a tag worktree yields the tag's tree, the working tree also
// yields a new guide before it is committed.
export function listRepoFiles(source) {
  const git = (...args) => spawnSync('git', ['-C', source, ...args], { encoding: 'utf8', maxBuffer: 1 << 26 });
  const prefix = git('rev-parse', '--show-prefix');
  if (prefix.status !== 0 || prefix.stdout.trim() !== '') {
    throw new Error(`Source ${source} is not the root of a git checkout`);
  }
  const listed = git('ls-files', '-z', '--cached', '--others', '--exclude-standard');
  if (listed.status !== 0) throw new Error(`git ls-files failed in ${source}: ${listed.stderr}`);
  const files = new Set(listed.stdout.split('\0').filter(Boolean));
  return [...files].filter((path) => existsSync(join(source, path))).sort();
}

const FENCE = /^\s*(`{3,}|~{3,})/;
const FORBIDDEN_OUTSIDE_FENCES = [
  [/^\s*<<</, 'a `<<<` snippet import'],
  [/^\s*<(script|style)(\s|>|$)/i, 'a <script> or <style> block'],
];

// Front matter and @include run before markdown-it whatever the context; snippets and SFC blocks only outside fences.
export function sourceProblems(repoPath, text) {
  const problems = [];
  const report = (index, what) => problems.push(`${repoPath}:${index + 1}: ${what} is not allowed on the site`);
  let fence = null;
  text.split('\n').forEach((line, index) => {
    if (index === 0 && /^\uFEFF?---/.test(line)) report(index, 'front matter');
    if (/<!--\s*@include:/.test(line)) report(index, 'an `<!--@include:` directive');
    const marker = FENCE.exec(line)?.[1];
    if (fence) {
      if (marker && marker[0] === fence[0] && marker.length >= fence.length && line.trim() === marker) fence = null;
      return;
    }
    if (marker) { fence = marker; return; }
    FORBIDDEN_OUTSIDE_FENCES.forEach(([pattern, what]) => pattern.test(line) && report(index, what));
  });
  return problems;
}

function stagedPages(files, build) {
  const pages = files.filter((path) => build.pagePath(path) !== null);
  const bySitePath = new Map();
  for (const path of pages) {
    const other = bySitePath.get(build.pagePath(path));
    if (other) throw new Error(`${other} and ${path} map to the same site page ${build.pagePath(path)}`);
    bySitePath.set(build.pagePath(path), path);
  }
  return pages;
}

function prepareBuildDir(out) {
  if (existsSync(out) && readdirSync(out).length > 0) throw new Error(`Build dir ${out} is not empty`);
  mkdirSync(out, { recursive: true });
}

// files defaults to the source's git listing; a caller that reads a tag without a worktree passes the tag's tree.
export function stageContent({ source, out, descriptor: input, files = listRepoFiles(source), build = copyBuild }) {
  const descriptor = validateDescriptor(input);
  const pages = stagedPages(files, build);
  const texts = new Map(pages.map((path) => [path, readFileSync(join(source, path), 'utf8')]));
  const problems = pages.flatMap((path) => sourceProblems(path, texts.get(path)));
  if (problems.length) throw new Error(`Content uses VitePress build-time features:\n  ${problems.join('\n  ')}`);
  prepareBuildDir(out);
  for (const [path, text] of texts) {
    mkdirSync(dirname(join(out, path)), { recursive: true });
    writeFileSync(join(out, path), wrapVPre(text));
  }
  writeFileSync(join(out, DESCRIPTOR_FILE), `${JSON.stringify(descriptor, null, 2)}\n`);
  writeFileSync(join(out, REPO_FILES_FILE), `${JSON.stringify(files)}\n`);
  return { descriptor, pages };
}

function directoriesOf(files) {
  const dirs = new Set(['']);
  for (const file of files) {
    for (let dir = dirname(file); dir !== '.' && !dirs.has(dir); dir = dirname(dir)) dirs.add(dir);
  }
  return dirs;
}

// The context the content markdown plugins work with: descriptor, build paths, base and the repo file index.
export function readSite(dir, build = copyBuild) {
  const descriptor = validateDescriptor(JSON.parse(readFileSync(join(dir, DESCRIPTOR_FILE), 'utf8')));
  const files = JSON.parse(readFileSync(join(dir, REPO_FILES_FILE), 'utf8'));
  return {
    ...descriptor,
    srcDir: resolve(dir),
    base: build.base(descriptor),
    files: new Set(files),
    dirs: directoriesOf(files),
    pagePath: build.pagePath,
    crossBuildUrl: build.crossBuildUrl,
  };
}

// The repo path of the page VitePress renders, if it is a staged content page. VitePress passes the rewritten file as
// env.path and the source as env.realPath; local search passes only env.path, which is then the source.
export function contentPage(site, env) {
  const file = env?.realPath ?? env?.path;
  if (!file) return null;
  const repoPath = relative(site.srcDir, file).split(sep).join('/');
  return site.files.has(repoPath) && site.pagePath(repoPath) !== null ? repoPath : null;
}
