// The publish decision and the versions.json writer (plan U5, R6, R8–R10, R18, R21; table "Version decision" and the
// root-refresh rule). build.mjs (U13) passes the stored /fieldset/versions.json (null before the first publish) and the
// release being built; the store job (U7) re-checks the order with store/version-order.jq, which runs the same vectors
// file. The stored file comes from this writer, so anything malformed in it is an error, never silently overwritten.
import { compareVersions, isMinor, parseTag, readCopy, SCHEMA } from './versions-reader.mjs';

const FIRST_MINOR = [2, 1]; // R10: versions before 2.1 get no copy
const SHA = /^[0-9a-f]{40}$/;
const DIGITS = /^[0-9]+$/;

// The tags that get a copy: finals and rc.N pre-releases (R21). store/version-order.jq accepts exactly these.
export function isCopyTag(tag) {
  const prerelease = parseTag(tag)?.prerelease;
  if (prerelease === undefined) return false;
  return prerelease === null || (prerelease.length === 2 && prerelease[0] === 'rc' && DIGITS.test(prerelease[1]));
}

const belowFirstMinor = ({ core: [major, minor] }) => {
  const [firstMajor, firstMinor] = FIRST_MINOR;
  return Number(major) < firstMajor || (Number(major) === firstMajor && Number(minor) < firstMinor);
};

// The release from its tag; the other metadata fields are optional and must agree with the tag.
function incomingRelease(release) {
  const { tag, builtWith } = release ?? {};
  if (tag === null || tag === undefined || [tag, release.version, release.minor].includes('dev')) {
    throw new Error('dev builds are never published: decide() needs a release tag');
  }
  const parsed = parseTag(tag);
  if (!parsed) throw new Error(`${JSON.stringify(tag)} is not a release tag`);
  const facts = {
    version: tag.slice(1), minor: `${parsed.core[0]}.${parsed.core[1]}`, prerelease: parsed.prerelease !== null,
    channel: parsed.prerelease?.[0] ?? 'stable',
  };
  const disagreeing = Object.keys(facts).filter((key) => release[key] !== undefined && release[key] !== facts[key]);
  if (disagreeing.length) throw new Error(`Release metadata disagrees with ${tag}: ${disagreeing.join(', ')}`);
  if (typeof builtWith !== 'string' || !SHA.test(builtWith)) {
    throw new Error(`builtWith must be the tooling commit's full SHA, got ${JSON.stringify(builtWith)}`);
  }
  return { ...facts, tag, builtWith, parsed };
}

// Known fields in schema order, then any unknown fields as stored (schema 1 is additive only).
function canonicalCopy({ minor, version, tag, prerelease, builtWith, ...unknown }) {
  return { minor, version, tag, prerelease, builtWith, ...unknown };
}

function copyProblem(entry) {
  if (!readCopy(entry)) return 'has an invalid minor, version, tag or prerelease';
  if (!isCopyTag(entry.tag)) return 'is not a final or rc.N copy';
  if (belowFirstMinor(parseTag(entry.tag))) return 'is below 2.1';
  return typeof entry.builtWith === 'string' && SHA.test(entry.builtWith) ? null : 'has no full builtWith SHA';
}

// latest: the newest final, or the newest pre-release while no final exists (R8).
function latestOf(copies) {
  const finals = copies.filter((copy) => !copy.prerelease);
  const pool = finals.length ? finals : copies;
  return pool.reduce((best, copy) => (compareVersions(copy.version, best.version) > 0 ? copy : best)).minor;
}

function storedState(stored) {
  if (stored === null || stored === undefined) return { copies: [], unknown: {} };
  const fail = (what) => { throw new Error(`Stored versions.json ${what}`); };
  const isRecord = typeof stored === 'object' && !Array.isArray(stored);
  if (!isRecord || stored.schema !== SCHEMA) fail(`has schema ${JSON.stringify(stored?.schema)}; this writer knows 1`);
  const { schema, latest, copies, ...unknown } = stored;
  if (!Array.isArray(copies) || copies.length === 0) fail('has no copies');
  copies.forEach((entry, index) => {
    const problem = copyProblem(entry);
    if (problem) fail(`copy ${index} (${JSON.stringify(entry?.minor)}) ${problem}`);
  });
  if (new Set(copies.map((copy) => copy.minor)).size !== copies.length) fail('lists a minor twice');
  const expected = latestOf(copies);
  if (!isMinor(latest) || latest !== expected) fail(`has latest ${JSON.stringify(latest)}, not ${expected}`);
  return { copies: copies.map(canonicalCopy), unknown };
}

const publish = (reason) => ({ publish: true, reason });
const skip = (reason) => ({ publish: false, reason });

// The decision table for one release against its minor's stored copy (undefined when the minor has none).
function verdict(incoming, existing) {
  if (!isCopyTag(incoming.tag)) {
    const { channel } = incoming;
    return skip(channel === 'rc' ? 'only rc.N pre-releases get a copy' : `${channel} tags get no copy`);
  }
  if (belowFirstMinor(incoming.parsed)) return skip('versions before 2.1 get no copy');
  if (!existing) return publish(`first copy of ${incoming.minor}`);
  if (!existing.prerelease && incoming.prerelease) return skip(`final copy exists: ${existing.version}`);
  if (existing.prerelease && !incoming.prerelease) {
    return publish(`${incoming.version} replaces the pre-release ${existing.version}`);
  }
  const order = compareVersions(incoming.version, existing.version);
  if (order < 0) return skip(`older than the stored ${existing.version}`);
  return publish(order === 0 ? `re-run of ${incoming.version}` : `${incoming.version} replaces ${existing.version}`);
}

// { action: 'publish'|'skip', reason, versions, refreshRoot, minor }. versions is the new versions.json for a publish
// and the stored one, unchanged, for a skip. The root refreshes when the published entry becomes latest, which is also
// the case for an equal re-run of the newest version.
export function decide(stored, release) {
  const incoming = incomingRelease(release);
  const state = storedState(stored);
  const { minor } = incoming;
  const result = verdict(incoming, state.copies.find((copy) => copy.minor === minor));
  const { reason } = result;
  if (!result.publish) return { action: 'skip', reason, versions: stored ?? null, refreshRoot: false, minor };
  const { version, tag, prerelease, builtWith } = incoming;
  const others = state.copies.filter((copy) => copy.minor !== minor);
  const copies = [...others, { minor, version, tag, prerelease, builtWith }]
    .sort((a, b) => compareVersions(b.version, a.version));
  const versions = { schema: SCHEMA, latest: latestOf(copies), copies, ...state.unknown };
  return { action: 'publish', reason, versions, refreshRoot: versions.latest === minor, minor };
}

// The file as stored and served: two-space JSON in the writer's key order, with a trailing newline.
export const serialize = (versions) => `${JSON.stringify(versions, null, 2)}\n`;
