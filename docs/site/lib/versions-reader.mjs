// Reads /fieldset/versions.json for the version switcher, the notices, latest/ and 404 (plan U5; KTDs "versions.json
// schema 1 is frozen" and "Clients treat versions.json as hostile"). Old copies run this code forever against a file on
// an origin shared with every beyerenrico.github.io site, so it trusts nothing: it checks every field it uses, ignores
// unknown fields, falls back to the values baked in at build time for malformed input or a schema above 1, and builds
// only /fieldset/ URLs from minors matching ^\d+\.\d+$. Every string of a model is a checked minor, version, tag or
// URL; render them as text, never with v-html. Browser-safe and import-free: test/fixtures/versions-reader-v1.mjs is a
// frozen copy of this file as first released, and the current writer's output must keep parsing with it.

export const SITE_ROOT = '/fieldset/';
export const VERSIONS_URL = '/fieldset/versions.json'; // literal: withBase would point inside the copy
export const LATEST_URL = '/fieldset/latest/';
export const SCHEMA = 1;

const MINOR = /^\d+\.\d+$/;
// ReleaseVersion::PATTERN (app/Domain/Instance/Release/ReleaseVersion.php); test/fixtures/version-vectors.json, which
// ReleaseVersion also runs, keeps the two in step.
const NUMBER = '(0|[1-9][0-9]*)';
const IDENTIFIER = '(?:0|[1-9][0-9]*|[0-9]*[A-Za-z-][0-9A-Za-z-]*)';
const VERSION = new RegExp(`^${NUMBER}\\.${NUMBER}\\.${NUMBER}(?:-((?:alpha|beta|rc)(?:\\.${IDENTIFIER})*))?$`);
const DIGITS = /^[0-9]+$/;
// Path segments inside a copy: no empty, dot, encoded or scheme-like segment can leave /fieldset/<minor>/.
const COPY_PATH = /^(?:[A-Za-z0-9][\w.-]*\/)*(?:[A-Za-z0-9][\w.-]*)?$/;

const isRecord = (value) => value !== null && typeof value === 'object' && !Array.isArray(value);
// Own properties only, so an inherited or polluted prototype cannot supply a field.
const field = (record, key) => (Object.hasOwn(record, key) ? record[key] : undefined);
const order = (a, b) => (a < b ? -1 : a > b ? 1 : 0);
export const isMinor = (value) => typeof value === 'string' && MINOR.test(value);

// { core: [major, minor, patch] as digit strings, prerelease: identifiers or null }, or null when not a version.
export function parseVersion(version) {
  const match = typeof version === 'string' ? VERSION.exec(version) : null;
  return match && { core: match.slice(1, 4), prerelease: match[4] === undefined ? null : match[4].split('.') };
}

// A git tag (v2.1.0-rc.1): the leading v is required, as in ReleaseVersion::fromTag.
export const parseTag = (tag) => (typeof tag === 'string' && tag.startsWith('v') ? parseVersion(tag.slice(1)) : null);

// Digit strings without leading zeros: the longer one is larger, equal lengths compare as text, exact at any size.
const compareNumbers = (a, b) => order(a.length, b.length) || order(a, b);

// Numeric identifiers compare numerically and rank below alphanumeric ones (semver 11.4, LEARNINGS 89).
function compareIdentifiers(a, b) {
  const [aNumeric, bNumeric] = [DIGITS.test(a), DIGITS.test(b)];
  if (aNumeric && bNumeric) return compareNumbers(a, b);
  if (aNumeric !== bNumeric) return aNumeric ? -1 : 1;
  return order(a, b);
}

// Semver precedence of two parsed versions: -1, 0 or 1. A final ranks above its pre-releases.
export function compareParsed(a, b) {
  for (let i = 0; i < 3; i += 1) {
    const core = compareNumbers(a.core[i], b.core[i]);
    if (core !== 0) return core;
  }
  if (a.prerelease === null || b.prerelease === null) {
    return order(Number(a.prerelease === null), Number(b.prerelease === null));
  }
  const shared = Math.min(a.prerelease.length, b.prerelease.length);
  for (let i = 0; i < shared; i += 1) {
    const identifier = compareIdentifiers(a.prerelease[i], b.prerelease[i]);
    if (identifier !== 0) return identifier;
  }
  return order(a.prerelease.length, b.prerelease.length);
}

function parsedOrThrow(parse, value) {
  const parsed = parse(value);
  const kind = parse === parseTag ? 'tag' : 'version';
  if (!parsed) throw new TypeError(`${JSON.stringify(value)} is not a release ${kind}`);
  return parsed;
}
export const compareVersions = (a, b) => compareParsed(parsedOrThrow(parseVersion, a), parsedOrThrow(parseVersion, b));
export const compareTags = (a, b) => compareParsed(parsedOrThrow(parseTag, a), parsedOrThrow(parseTag, b));

// /fieldset/<minor>/<path>: home for an invalid minor, the copy's index for an unsafe path.
export function copyUrl(minor, path = '') {
  if (!isMinor(minor)) return SITE_ROOT;
  return `${SITE_ROOT}${minor}/${typeof path === 'string' && COPY_PATH.test(path) ? path : ''}`;
}

// One copy entry with the fields readers use, or null when any of them is missing or inconsistent.
export function readCopy(entry) {
  if (!isRecord(entry)) return null;
  const [minor, version, tag, prerelease] = ['minor', 'version', 'tag', 'prerelease'].map((key) => field(entry, key));
  const parsed = parseVersion(version);
  if (!parsed || !isMinor(minor) || minor !== `${parsed.core[0]}.${parsed.core[1]}`) return null;
  if (tag !== `v${version}` || prerelease !== (parsed.prerelease !== null)) return null;
  return { minor, version, tag, prerelease, url: copyUrl(minor) };
}

function parseJson(text) {
  try {
    return JSON.parse(text);
  } catch {
    return null;
  }
}

function readModel(input) {
  const data = typeof input === 'string' ? parseJson(input) : input;
  if (!isRecord(data) || field(data, 'schema') !== SCHEMA) return null;
  const entries = field(data, 'copies');
  const copies = Array.isArray(entries) ? Array.from(entries, (entry) => readCopy(entry)) : [];
  if (copies.length === 0 || copies.includes(null)) return null;
  if (new Set(copies.map((copy) => copy.minor)).size !== copies.length) return null;
  const latest = field(data, 'latest');
  if (!copies.some((copy) => copy.minor === latest)) return null;
  copies.sort((a, b) => compareVersions(b.version, a.version));
  return { latest, copies };
}

// input: the fetched text (or parsed value) of versions.json; baked: the versions.json known at build time. Returns
// { latest, copies: [{ minor, version, tag, prerelease, url }] newest first, fromBaked }; never throws.
export function readVersions(input, baked) {
  const live = readModel(input);
  if (live) return { ...live, fromBaked: false };
  return { ...(readModel(baked) ?? { latest: null, copies: [] }), fromBaked: true };
}
