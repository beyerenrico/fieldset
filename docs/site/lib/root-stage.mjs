// Stages the root build (plan U4, R4, R5, R11; "Assembled site and ownership"): the spec of form-definition 1.0 from
// the release's source and the site-only root pages, into one dir that root/.vitepress/config.mjs builds.
//
//   <stage>/site.json, repo-files.json            descriptor and repo file index (lib/stage.mjs)
//   <stage>/spec/form-definition/1.0/README.md     -> page spec/form-definition/1.0/ (U2 rules, as text)
//   <stage>/spec/form-definition/1.0/CHANGELOG.md  -> page spec/form-definition/1.0/changelog
//   <stage>/public/spec/form-definition/1.0/*.json  every JSON file of that directory, byte for byte (R5, the $id)
//   <stage>/index.md, 404.md, latest/index.md       site-only pages from the root content dir
//   <stage>/public/screenshots/*                    the home page's screenshots (U9)
//   <stage>/root.json                               the versions.json the pages bake in
//
// The root content dir is docs/site/root/ of the release when its tree has one, else this tooling's (2.1 predates the
// site); its .vitepress/ is tooling and always comes from this checkout.
import { copyFileSync, existsSync, lstatSync, mkdirSync, readdirSync, readFileSync, writeFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { listRepoFiles, readSite, SITE_ROOT, stageContent, validateDescriptor } from './stage.mjs';
import { readVersions } from './versions-reader.mjs';

export const SPEC_DIR = 'spec/form-definition/1.0';
export const ROOT_DATA_FILE = 'root.json';
export const ROOT_PAGES = Object.freeze(['index.md', '404.md', 'latest/index.md']);
export const SCREENSHOTS_DIR = 'public/screenshots';
export const TOOLING_CONTENT_DIR = fileURLToPath(new URL('../root/', import.meta.url));
const SPEC_PAGES = Object.freeze({
  [`${SPEC_DIR}/README.md`]: `${SPEC_DIR}/index.md`,
  [`${SPEC_DIR}/CHANGELOG.md`]: `${SPEC_DIR}/changelog.md`,
});
const META_SCHEMA = `${SPEC_DIR}/meta-schema.json`;
const SCREENSHOT_FILE = /^[A-Za-z0-9][\w-]*\.(png|webp|jpe?g)$/;
const isSpecJson = (path) => path.startsWith(`${SPEC_DIR}/`) && /^[^/]+\.json$/.test(path.slice(SPEC_DIR.length + 1));

// The build profile of the root (lib/stage.mjs): base /fieldset/, the spec README and changelog as its content pages,
// and the spec's JSON files as raw files of this build, so the README's meta-schema.json and roles.json links stay
// page-relative file links instead of GitHub URLs.
export const rootBuild = Object.freeze({
  base: () => SITE_ROOT,
  pagePath: (repoPath) => (Object.hasOwn(SPEC_PAGES, repoPath) ? SPEC_PAGES[repoPath] : null),
  crossBuildUrl: (repoPath) => (isSpecJson(repoPath) ? `${SITE_ROOT}${repoPath}` : null),
});

// docs/site/root/ of the source when it holds the home page, else this tooling's.
export function rootContentDir(source) {
  const dir = join(source, 'docs/site/root');
  return existsSync(join(dir, 'index.md')) ? dir : TOOLING_CONTENT_DIR;
}

// The versions.json fields the root pages read; null before the first release. Malformed input is an error: it comes
// from the writer (lib/versions.mjs), never from a visitor.
export function rootVersions(versions) {
  if (versions === null || versions === undefined) return null;
  const read = readVersions(versions, null);
  if (read.fromBaked) throw new Error('versions is not a valid versions.json (schema 1)');
  const copies = read.copies.map(({ minor, version, tag, prerelease }) => ({ minor, version, tag, prerelease }));
  return { schema: 1, latest: read.latest, copies };
}

// A release refreshes the root only as the newest version (lib/versions.mjs decide().refreshRoot), so its tag must be
// versions.json's latest copy. A working-tree build (tag null) takes any versions.json, or none.
function checkRelease(descriptor, baked) {
  if (descriptor.tag === null) return;
  const latest = baked?.copies.find((copy) => copy.minor === baked.latest);
  if (latest?.tag !== descriptor.tag) {
    throw new Error(`The root is built from the latest release; ${descriptor.tag} is not versions.json's latest copy`);
  }
}

function regularFile(path, what) {
  const stat = lstatSync(path, { throwIfNoEntry: false });
  if (!stat) throw new Error(`${what} is missing: ${path}`);
  if (!stat.isFile()) throw new Error(`${what} must be a regular file, not a link or directory: ${path}`);
  return path;
}

// The root content files to stage, as [stage path, source path]: the three pages and the screenshots.
function rootContent(dir) {
  const pages = ROOT_PAGES.map((page) => [page, regularFile(join(dir, page), `Root page ${page}`)]);
  const shots = join(dir, SCREENSHOTS_DIR);
  if (!existsSync(shots)) return pages;
  if (!lstatSync(shots).isDirectory()) throw new Error(`${shots} must be a directory, not a link`);
  const files = readdirSync(shots).sort().map((name) => {
    if (!SCREENSHOT_FILE.test(name)) throw new Error(`Screenshot ${name} must be named <name>.png|webp|jpg`);
    return [`${SCREENSHOTS_DIR}/${name}`, regularFile(join(shots, name), `Screenshot ${name}`)];
  });
  return [...pages, ...files];
}

function copyInto(out, entries) {
  for (const [stagePath, from] of entries) {
    mkdirSync(dirname(join(out, stagePath)), { recursive: true });
    copyFileSync(from, join(out, stagePath));
  }
}

// source: root of a checkout of the release (a tag worktree, or the working tree for a dev build); out: an empty dir;
// descriptor: the release's site descriptor (lib/stage.mjs validateDescriptor; the same one its copy is staged with);
// versions: the versions.json the root bakes in (decide().versions for a publish, the stored file or null for a dev
// build); contentDir: the root content dir (default rootContentDir(source)); files: the source's file list (default
// its git listing). Returns { descriptor, pages, specFiles, content } as repo and stage paths.
export function stageRoot({
  source, out, descriptor: input, versions, contentDir = rootContentDir(source), files = listRepoFiles(source),
}) {
  const descriptor = validateDescriptor(input);
  const baked = rootVersions(versions);
  checkRelease(descriptor, baked);
  const missing = [...Object.keys(SPEC_PAGES), META_SCHEMA].filter((path) => !files.includes(path));
  if (missing.length) throw new Error(`The source lacks spec files: ${missing.join(', ')}`);
  const specFiles = files.filter(isSpecJson);
  [...Object.keys(SPEC_PAGES), ...specFiles].forEach((path) => regularFile(join(source, path), `Spec file ${path}`));
  const content = rootContent(contentDir);
  const { pages } = stageContent({ source, out, descriptor, files, build: rootBuild });
  copyInto(out, specFiles.map((path) => [`public/${path}`, join(source, path)]));
  copyInto(out, content);
  writeFileSync(join(out, ROOT_DATA_FILE), `${JSON.stringify({ versions: baked }, null, 2)}\n`);
  return { descriptor, pages, specFiles, content: content.map(([stagePath]) => stagePath) };
}

// The context root/.vitepress/config.mjs builds with: readSite's for the root profile plus the baked versions.json.
export function readRootSite(dir) {
  const { versions } = JSON.parse(readFileSync(join(dir, ROOT_DATA_FILE), 'utf8'));
  return { ...readSite(dir, rootBuild), versions: rootVersions(versions) };
}
