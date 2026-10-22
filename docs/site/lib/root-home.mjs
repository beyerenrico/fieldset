// The root home page's data (plan U4, R8, R11): the install command of the newest release, the links into the newest
// release's documentation and the screenshots, from the versions.json the root is built with (baked) and the page's
// front matter (docs/site/root/index.md). Build time only decides the URLs: the root refreshes whenever `latest`
// moves (lib/versions.mjs), so its baked latest copy is the live one. Every URL is built here from a checked minor or a
// fixed path; front matter names link targets by key and can never supply a URL. Texts render as text. Browser-safe.
import { installCommand } from './theme-versions.mjs';
import { copyUrl, LATEST_URL, readVersions, SITE_ROOT } from './versions-reader.mjs';

// Link targets the page may name: a path in the newest release's copy, or a page of the root.
const COPY_TARGETS = Object.freeze({
  guides: '', install: 'install', integrations: 'api', 'api-contract': 'field-app-api-v1',
  'api-reference': 'api-reference.html', changelog: 'changelog',
});
const ROOT_TARGETS = Object.freeze({ spec: 'spec/form-definition/1.0/' });
export const LINK_TARGETS = Object.freeze([...Object.keys(COPY_TARGETS), ...Object.keys(ROOT_TARGETS)]);
const SCREENSHOT = /^screenshots\/[A-Za-z0-9][\w-]*\.(png|webp|jpe?g)$/;
const ACTION_THEMES = new Set(['brand', 'alt']);

// The reader's model of the baked versions.json; null (no release yet, a dev build) yields no copies.
export const bakedRead = (versions) => readVersions(null, versions);

// { <target>: url }: copy targets in the latest copy, or below /fieldset/latest/ while no copy is known.
export function docsUrls(read) {
  const copyTarget = (path) => (read.latest ? copyUrl(read.latest, path) : `${LATEST_URL}${path}`);
  return Object.freeze({
    ...Object.fromEntries(Object.entries(COPY_TARGETS).map(([key, path]) => [key, copyTarget(path)])),
    ...Object.fromEntries(Object.entries(ROOT_TARGETS).map(([key, path]) => [key, `${SITE_ROOT}${path}`])),
  });
}

// The newest release's install command: the newest final, or the newest pre-release while no final exists (that is
// versions.json's latest), labelled as such. null without a release.
export function installModel(read, repository) {
  const copy = read.copies.find((item) => item.minor === read.latest);
  const command = copy ? installCommand({ ...copy, repository }) : null;
  return command && { version: copy.version, prerelease: copy.prerelease, command };
}

// What the build bakes into themeConfig for the home page.
export function homeModel(versions, repository) {
  const read = bakedRead(versions);
  return { install: installModel(read, repository), urls: docsUrls(read) };
}

const isText = (value) => typeof value === 'string' && value.trim() !== '';

function listOf(value, what) {
  if (value === undefined || value === null) return [];
  if (!Array.isArray(value)) throw new Error(`Home page front matter: ${what} must be a list`);
  return value;
}

function linkOf(entry, urls, what) {
  const to = entry?.to;
  if (Object.hasOwn(urls, to)) return urls[to];
  throw new Error(`Home page front matter: ${what} links to ${JSON.stringify(to)}, not to ${LINK_TARGETS.join(', ')}`);
}

function textOf(entry, key, what) {
  if (!isText(entry?.[key])) throw new Error(`Home page front matter: ${what} needs a non-empty ${key}`);
  return entry[key];
}

// front matter `actions`: [{ text, to, theme? }] -> [{ text, url, theme }]; theme is brand (default) or alt.
export function homeActions(entries, urls) {
  return listOf(entries, 'actions').map((entry, index) => {
    const what = `actions[${index}]`;
    const theme = entry?.theme ?? 'brand';
    if (!ACTION_THEMES.has(theme)) throw new Error(`Home page front matter: ${what} has a theme other than brand, alt`);
    return { text: textOf(entry, 'text', what), url: linkOf(entry, urls, what), theme };
  });
}

// front matter `docs`: [{ title, details, to }] -> [{ title, details, url }].
export function homeDocs(entries, urls) {
  return listOf(entries, 'docs').map((entry, index) => ({
    title: textOf(entry, 'title', `docs[${index}]`), details: textOf(entry, 'details', `docs[${index}]`),
    url: linkOf(entry, urls, `docs[${index}]`),
  }));
}

// front matter `screenshots`: [{ src: screenshots/<file>, alt, caption? }] -> [{ url, alt, caption }]. The files live
// in docs/site/root/public/screenshots/ (U9); an empty list renders no screenshot section.
export function homeScreenshots(entries) {
  return listOf(entries, 'screenshots').map((entry, index) => {
    const what = `screenshots[${index}]`;
    if (typeof entry?.src !== 'string' || !SCREENSHOT.test(entry.src)) {
      throw new Error(`Home page front matter: ${what} src must be screenshots/<name>.png|webp|jpg`);
    }
    const caption = entry.caption === undefined ? null : textOf(entry, 'caption', what);
    return { url: `${SITE_ROOT}${entry.src}`, alt: textOf(entry, 'alt', what), caption };
  });
}
