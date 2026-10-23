// Resolves the links of content pages at parse time (plan U2, R13, R14; KTD "Links are resolved in a markdown-it core
// rule"). The rule runs before VitePress's link renderer, so its dead-link check and `base` see the result:
// - a staged page becomes an absolute site path (`/install.md#mail`) that VitePress cleans and prefixes with `base`;
// - a target of another build (the root spec) becomes page-relative with target="_self": VitePress prefixes `base` to
//   every `/` link, and its router would swallow a same-origin click without a target;
// - any other file or directory of the repo opens on GitHub at the copy's ref (tag, or commit in dev mode);
// - a missing target throws, naming the source file, line and target.
// The agent guide's blob/main/docs/guide/* links become internal here only; its source stays byte-identical (`sign`).
import { posix } from 'node:path';
import { contentPage, wrapperLines } from './stage.mjs';

const ABSOLUTE = /^([a-z][a-z\d+.-]*:|\/\/)/i;

function splitHref(href) {
  const [rest, ...hash] = href.split('#');
  const [path, ...query] = rest.split('?');
  return { path, suffix: `${query.length ? `?${query.join('?')}` : ''}${hash.length ? `#${hash.join('#')}` : ''}` };
}

const isStaged = (site, repoPath) => site.files.has(repoPath) && site.pagePath(repoPath) !== null;
const encodePath = (path) => path.split('/').map(encodeURIComponent).join('/');

function githubUrl(site, kind, repoPath) {
  return `https://github.com/${site.repository}/${kind}/${site.ref}${repoPath ? `/${encodePath(repoPath)}` : ''}`;
}

// The page's URL directory is base plus its site path's directory (clean URLs: /fieldset/2.1/archive/1.0-upgrade).
function pageRelative(url, from, site) {
  const pageDir = posix.join(site.base, posix.dirname(site.pagePath(from)));
  const path = posix.relative(pageDir, url);
  if (path === '') return './';
  return url.endsWith('/') ? `${path}/` : path;
}

function fileTarget(repoPath, suffix, from, site) {
  if (isStaged(site, repoPath)) return { href: `/${encodePath(site.pagePath(repoPath))}${suffix}` };
  const url = site.crossBuildUrl(repoPath);
  if (url) return { href: `${pageRelative(url, from, site)}${suffix}`, target: '_self' };
  return { href: `${githubUrl(site, 'blob', repoPath)}${suffix}` };
}

// A directory whose README is on the site opens that page, as GitHub shows the README below the listing.
function dirTarget(repoPath, suffix, from, site) {
  const readme = repoPath ? `${repoPath}/README.md` : 'README.md';
  const onSite = site.files.has(readme) && (isStaged(site, readme) || site.crossBuildUrl(readme));
  return onSite ? fileTarget(readme, suffix, from, site) : { href: `${githubUrl(site, 'tree', repoPath)}${suffix}` };
}

// The repo path a link points at, '' for the repo root, or null above the root. A leading / is the repo root, as on
// GitHub.
function targetPath(decoded, from) {
  const joined = decoded.startsWith('/') ? decoded.slice(1) : posix.join(posix.dirname(from), decoded);
  const normal = posix.normalize(joined || '.').replace(/\/+$/, '');
  if (normal === '..' || normal.startsWith('../')) return null;
  return normal === '.' ? '' : normal;
}

function decodePath(path, where) {
  try {
    return decodeURIComponent(path);
  } catch {
    throw new Error(`${where} is not a valid URL path`);
  }
}

function mainBlobTarget(href, site) {
  const prefix = `https://github.com/${site.repository}/blob/main/`;
  if (!href.startsWith(prefix)) return null;
  const { path, suffix } = splitHref(href.slice(prefix.length));
  if (!/^docs\/guide\/.+\.md$/.test(path) || !isStaged(site, path)) return null;
  return { href: `/${encodePath(site.pagePath(path))}${suffix}` };
}

// Returns { href, target? } for a link to rewrite, or null to leave it as written. page is { repoPath, line }.
export function resolveLink(href, page, site) {
  if (href === '' || href.startsWith('#') || href.startsWith('?')) return null;
  if (ABSOLUTE.test(href)) return mainBlobTarget(href, site);
  const where = `${page.repoPath}:${page.line}: link target "${href}"`;
  const { path, suffix } = splitHref(href);
  const repoPath = targetPath(decodePath(path, where), page.repoPath);
  if (repoPath === null) throw new Error(`${where} points outside the repository`);
  if (site.files.has(repoPath)) return fileTarget(repoPath, suffix, page.repoPath, site);
  if (site.dirs.has(repoPath)) return dirTarget(repoPath, suffix, page.repoPath, site);
  throw new Error(`${where} does not exist in the repository at ${site.ref} (resolved to ${repoPath})`);
}

function linkTokens(tokens) {
  return tokens
    .filter((block) => block.type === 'inline' && block.children)
    .flatMap((block) => block.children.filter((child) => child.type === 'link_open').map((link) => [link, block]));
}

export function linksPlugin(md, site) {
  md.core.ruler.after('inline', 'fieldset_links', (state) => {
    const repoPath = contentPage(site, state.env);
    if (repoPath === null) return;
    const offset = wrapperLines(state.src);
    for (const [link, block] of linkTokens(state.tokens)) {
      const line = block.map ? block.map[0] + 1 - offset : '?';
      const resolved = resolveLink(link.attrGet('href') ?? '', { repoPath, line }, site);
      if (!resolved) continue;
      link.attrSet('href', resolved.href);
      if (resolved.target) link.attrSet('target', resolved.target);
    }
  });
}
