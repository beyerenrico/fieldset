// The version copy's theme logic (plan U3, R6, R7, R20): the switcher model, the notices and the one versions.json
// fetch per page load. The .vue components only render these models, as text. Browser-safe: it runs in the client
// bundle and in node:test. `copy` is the site descriptor baked at build time ({ minor, version, tag, prerelease,
// repository, ref }; tag null for a working-tree build, whose minor is `dev`). Everything read from versions.json goes
// through the U5 reader, which builds every URL from a checked minor under /fieldset/.
import {
  compareVersions, copyUrl, isMinor, LATEST_URL, parseTag, parseVersion, readVersions, SCHEMA, SITE_ROOT, VERSIONS_URL,
} from './versions-reader.mjs';

export const VERSIONS_KEY = 'fieldset:versions'; // provide/inject key of the page's versions loader
const REPOSITORY = /^[\w.-]+\/[\w.-]+$/;
// The flags of the versioned install command in docs/guide/install.md, verbatim.
const INSTALL_FLAGS = '--org "Example Ltd" --owner-email owner@example.com';

const isRelease = (copy) => copy.tag !== null && parseTag(copy.tag) !== null && isMinor(copy.minor);

// The exact command that installs this copy's release, or null for a working-tree build.
export function installCommand(copy) {
  if (!isRelease(copy) || !REPOSITORY.test(copy.repository)) return null;
  const version = copy.tag.slice(1);
  const url = `https://github.com/${copy.repository}/releases/download/${copy.tag}/install.sh`;
  return `curl --proto '=https' --tlsv1.2 -fsSL ${url} | sh -s -- --version ${version} ${INSTALL_FLAGS}`;
}

// The reader's fallback: what the build knew for sure, which is this copy alone, so a failed or hostile fetch shows
// no other copy and no newer version. A working-tree build is in no versions.json.
export function bakedVersions(copy) {
  if (!isRelease(copy)) return null;
  const { minor, version, tag, prerelease } = copy;
  return { schema: SCHEMA, latest: minor, copies: [{ minor, version, tag, prerelease }] };
}

async function fetchVersionsText(fetchImpl) {
  try {
    const response = await fetchImpl(VERSIONS_URL, { cache: 'no-cache', credentials: 'omit', redirect: 'error' });
    return response.ok ? await response.text() : null;
  } catch {
    return null;
  }
}

// One fetch per page load, shared by the switcher and the notice; resolves to the reader's model and never rejects.
export function createVersionsLoader(copy, fetchImpl = (...args) => globalThis.fetch(...args)) {
  let pending = null;
  return () => {
    pending ??= fetchVersionsText(fetchImpl).then((text) => readVersions(text, bakedVersions(copy)));
    return pending;
  };
}

const labelOf = (copy) => (isRelease(copy) ? copy.version : 'dev');

function currentEntry(copy) {
  const release = isRelease(copy);
  return {
    minor: copy.minor, version: labelOf(copy), prerelease: release && copy.prerelease, latest: false, current: true,
    url: release ? copyUrl(copy.minor) : null,
  };
}

// read: the loader's model, or null before the fetch (also during SSR). Entries link to each copy's index; a copy
// versions.json does not list (a working-tree build, or a stale file) is shown first as the current one.
export function switcherModel(copy, read) {
  const entries = (read?.copies ?? []).map(({ minor, version, prerelease, url }) => ({
    minor, version, prerelease, latest: minor === read.latest, current: minor === copy.minor, url,
  }));
  if (!entries.some((item) => item.current)) entries.unshift(currentEntry(copy));
  return { label: labelOf(copy), prerelease: isRelease(copy) && copy.prerelease, entries, home: SITE_ROOT };
}

// R20: versions.json's latest is the newest final, unless no final exists; only a final counts as newer.
function newerNotice(copy, read) {
  if (!read || !isRelease(copy) || !parseVersion(copy.version)) return null;
  const latest = read.copies.find((item) => item.minor === read.latest);
  if (!latest || latest.prerelease || compareVersions(latest.version, copy.version) <= 0) return null;
  return { kind: 'newer', version: latest.version, minor: latest.minor, url: latest.url };
}

// The notices above every page, in display order. The pre-release (R7) and development notices depend only on the
// build, so they render server-side; the newer-version notice appears once versions.json is read.
export function notices(copy, read) {
  const list = [];
  if (!isRelease(copy)) list.push({ kind: 'development', ref: String(copy.ref).slice(0, 12), url: LATEST_URL });
  else if (copy.prerelease) list.push({ kind: 'prerelease', version: copy.version, command: installCommand(copy) });
  const newer = newerNotice(copy, read);
  if (newer) list.push(newer);
  return list;
}
