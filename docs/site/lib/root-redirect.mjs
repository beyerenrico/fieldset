// Where the root's /fieldset/latest/ page and its 404 page send a visitor (plan U4, R8). GitHub Pages serves the root
// 404.html for every missing path below /fieldset/, so one mapping covers:
// - /fieldset/latest/<path>          -> /fieldset/<latest minor>/<path> (the fragment kept);
// - /fieldset/<known minor>/<missing> -> /fieldset/<minor>/;
// - /fieldset/<other minor>/...      -> "never published", linking the latest copy and its 1.0 archive guides;
// - anything else                    -> "not found", linking /fieldset/latest/ (or the latest copy) and /fieldset/.
// The path comes from location and is hostile input: it is never decoded, and every URL is built by copyUrl from a
// checked minor, so encoded dots, `//`, backslashes or a scheme can only ever lead to a /fieldset/ page. read is the
// U5 reader's model of versions.json (live, or the root's baked copy when the fetch failed). Browser-safe.
import { FIRST_MINOR } from './versions.mjs';
import { copyUrl, isMinor, LATEST_URL, SITE_ROOT } from './versions-reader.mjs';

export const ARCHIVE_PAGE = 'archive/1.0-install';
// A fragment as location.hash reports it: slugs, percent-encoded where not ASCII.
const FRAGMENT = /^#[\w.~%-]+$/;

const beforeFirstCopy = (minor) => {
  const [major, number] = minor.split('.').map(Number);
  return major < FIRST_MINOR[0] || (major === FIRST_MINOR[0] && number < FIRST_MINOR[1]);
};

// A redirect, or null when the target is the missing page itself (a copy without its index), which would loop.
function redirect(minor, url, pathname, hash = '') {
  if (url === pathname) return null;
  return { kind: 'redirect', minor, url: FRAGMENT.test(hash) ? `${url}${hash}` : url };
}

// The path a root page has before the browser's location is known (server render, hydration): latest/index.md is
// /fieldset/latest/, 404.md is /fieldset/404.
export const pagePathname = (relativePath) => `${SITE_ROOT}${String(relativePath)
  .replace(/(^|\/)index\.md$/, '$1').replace(/\.md$/, '')}`;

// location: { pathname, hash } as the browser reports them. Returns one of
//   { kind: 'redirect', minor, url }
//   { kind: 'never-published', version, latestUrl, archiveUrl, homeUrl }  (latestUrl, archiveUrl null without a copy)
//   { kind: 'no-release', homeUrl }                                      (latest/ while no copy is known)
//   { kind: 'not-found', latestUrl, homeUrl }
export function redirectModel({ pathname, hash = '' } = {}, read = { latest: null, copies: [], fromBaked: true }) {
  const latest = isMinor(read.latest) ? read.latest : null;
  const links = { latestUrl: latest ? copyUrl(latest) : LATEST_URL, homeUrl: SITE_ROOT };
  const notFound = { kind: 'not-found', ...links };
  if (typeof pathname !== 'string' || !pathname.startsWith(SITE_ROOT)) return notFound;
  const [first, ...rest] = pathname.slice(SITE_ROOT.length).split('/');
  if (first === 'latest') {
    if (!latest) return { kind: 'no-release', homeUrl: SITE_ROOT };
    const fragment = typeof hash === 'string' ? hash : '';
    return redirect(latest, copyUrl(latest, rest.join('/')), pathname, fragment) ?? notFound;
  }
  if (!isMinor(first)) return notFound;
  if (read.copies.some((copy) => copy.minor === first)) return redirect(first, copyUrl(first), pathname) ?? notFound;
  // A baked versions.json predates copies published since the root was built; only the rule of R10 is certain then.
  if (read.fromBaked && !beforeFirstCopy(first)) return notFound;
  return {
    kind: 'never-published', version: first, latestUrl: latest ? links.latestUrl : null,
    archiveUrl: latest ? copyUrl(latest, ARCHIVE_PAGE) : null, homeUrl: SITE_ROOT,
  };
}
