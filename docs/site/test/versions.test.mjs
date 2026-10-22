// Version rules (plan U5, R6, R8–R10, R18, R21; table "Version decision"): decide() applies the decision table to the
// stored versions.json and returns publish or skip, the new versions.json and whether the root refreshes. The tag order
// runs the vectors file that the jq program and ReleaseVersion also run.
import { describe, test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { compareTags, parseTag } from '../lib/versions-reader.mjs';
import { decide, isCopyTag, serialize } from '../lib/versions.mjs';

const VECTORS = JSON.parse(readFileSync(new URL('./fixtures/version-vectors.json', import.meta.url), 'utf8'));
const TOOLING = 'a'.repeat(40);
const NEWER_TOOLING = 'b'.repeat(40);

const release = (tag, builtWith = TOOLING) => ({ tag, builtWith });
// A store built the way production builds it: one publish per tag, in order.
function storeOf(...tags) {
  return tags.reduce((stored, tag) => {
    const decision = decide(stored, release(tag));
    assert.equal(decision.action, 'publish', `fixture publish of ${tag}: ${decision.reason}`);
    return decision.versions;
  }, null);
}
const entry = (minor, version, builtWith = TOOLING) => ({
  minor, version, tag: `v${version}`, prerelease: version.includes('-'), builtWith,
});

describe('tag order', () => {
  test('every tag of the ascending chain ranks above all tags before it', () => {
    VECTORS.ascending.forEach((a, i) => VECTORS.ascending.forEach((b, j) => {
      assert.equal(compareTags(a, b), Math.sign(i - j), `${a} against ${b}`);
    }));
  });

  test('every named pair has its expected order', () => {
    for (const { case: name, a, b, order } of VECTORS.pairs) assert.equal(compareTags(a, b), order, name);
  });

  test('invalid tags do not parse and cannot be compared', () => {
    for (const tag of VECTORS.invalid) {
      assert.equal(parseTag(tag), null, JSON.stringify(tag));
      assert.throws(() => compareTags(tag, 'v2.1.0'), /not a release tag/, JSON.stringify(tag));
    }
  });

  test('copy tags are the finals and rc.N pre-releases of the chain, nothing else', () => {
    for (const tag of VECTORS.ascending) assert.equal(isCopyTag(tag), !VECTORS.noCopy.includes(tag), tag);
    for (const tag of VECTORS.invalid) assert.equal(isCopyTag(tag), false, JSON.stringify(tag));
  });
});

describe('decision table', () => {
  test('an empty store plus v2.1.0-rc.1 publishes 2.1 as a pre-release, sets latest and refreshes the root', () => {
    const decision = decide(null, release('v2.1.0-rc.1'));
    assert.deepEqual(decision, {
      action: 'publish', reason: 'first copy of 2.1', minor: '2.1', refreshRoot: true,
      versions: { schema: 1, latest: '2.1', copies: [entry('2.1', '2.1.0-rc.1')] },
    });
  });

  test('AE2: store {2.1.0} plus v2.2.0-rc.1 publishes 2.2 and keeps latest 2.1 without a root refresh', () => {
    const decision = decide(storeOf('v2.1.0'), release('v2.2.0-rc.1'));
    assert.equal(decision.action, 'publish');
    assert.equal(decision.refreshRoot, false);
    assert.deepEqual(decision.versions, {
      schema: 1, latest: '2.1', copies: [entry('2.2', '2.2.0-rc.1'), entry('2.1', '2.1.0')],
    });
  });

  test('store {2.1.0-rc.1} plus v2.1.0 replaces 2.1 as final and refreshes the root', () => {
    const decision = decide(storeOf('v2.1.0-rc.1'), release('v2.1.0'));
    assert.equal(decision.action, 'publish');
    assert.match(decision.reason, /replaces the pre-release 2\.1\.0-rc\.1/);
    assert.equal(decision.refreshRoot, true);
    assert.deepEqual(decision.versions, { schema: 1, latest: '2.1', copies: [entry('2.1', '2.1.0')] });
  });

  test('store {2.1.0} plus v2.1.1-rc.1 skips: a pre-release never replaces a final copy', () => {
    const stored = storeOf('v2.1.0');
    const decision = decide(stored, release('v2.1.1-rc.1'));
    assert.deepEqual(decision, {
      action: 'skip', reason: 'final copy exists: 2.1.0', minor: '2.1', refreshRoot: false, versions: stored,
    });
  });

  test('store {2.2.0, 2.1.0} plus v2.1.1 publishes 2.1, keeps latest 2.2 and does not refresh the root', () => {
    const decision = decide(storeOf('v2.1.0', 'v2.2.0'), release('v2.1.1'));
    assert.equal(decision.action, 'publish');
    assert.equal(decision.refreshRoot, false);
    assert.deepEqual(decision.versions, {
      schema: 1, latest: '2.2', copies: [entry('2.2', '2.2.0'), entry('2.1', '2.1.1')],
    });
  });

  test('a re-run of v2.1.0 over {2.1.0} republishes with the new tooling and refreshes the root', () => {
    const decision = decide(storeOf('v2.1.0'), release('v2.1.0', NEWER_TOOLING));
    assert.equal(decision.action, 'publish');
    assert.equal(decision.reason, 're-run of 2.1.0');
    assert.equal(decision.refreshRoot, true);
    assert.deepEqual(decision.versions.copies, [entry('2.1', '2.1.0', NEWER_TOOLING)]);
  });

  test('v2.1.0 over {2.1.1} skips as older', () => {
    const decision = decide(storeOf('v2.1.0', 'v2.1.1'), release('v2.1.0'));
    assert.equal(decision.action, 'skip');
    assert.equal(decision.reason, 'older than the stored 2.1.1');
  });

  test('an rc copy is replaced by a newer or equal rc and kept against an older one', () => {
    assert.equal(decide(storeOf('v2.1.0-rc.9'), release('v2.1.0-rc.10')).action, 'publish');
    assert.equal(decide(storeOf('v2.1.0-rc.2'), release('v2.1.0-rc.2')).action, 'publish');
    assert.equal(decide(storeOf('v2.1.0-rc.10'), release('v2.1.0-rc.9')).reason, 'older than the stored 2.1.0-rc.10');
  });

  test('alpha, beta and other rc shapes skip; so do versions before 2.1', () => {
    const reasons = Object.fromEntries(['v2.2.0-beta.1', 'v2.2.0-alpha.1', 'v2.2.0-rc', 'v2.2.0-rc.1.1', 'v2.0.5',
      'v1.9.0', 'v2.0.0-rc.1'].map((tag) => [tag, decide(null, release(tag))]));
    for (const [tag, decision] of Object.entries(reasons)) {
      assert.equal(decision.action, 'skip', tag);
      assert.equal(decision.versions, null, tag);
      assert.equal(decision.refreshRoot, false, tag);
    }
    assert.equal(reasons['v2.2.0-beta.1'].reason, 'beta tags get no copy');
    assert.equal(reasons['v2.2.0-alpha.1'].reason, 'alpha tags get no copy');
    assert.equal(reasons['v2.2.0-rc.1.1'].reason, 'only rc.N pre-releases get a copy');
    assert.equal(reasons['v2.0.5'].reason, 'versions before 2.1 get no copy');
    assert.equal(reasons['v2.0.0-rc.1'].reason, 'versions before 2.1 get no copy');
  });

  test('a first copy of an older minor publishes without moving latest or the root', () => {
    const decision = decide(storeOf('v2.2.0'), release('v2.1.0-rc.1'));
    assert.equal(decision.action, 'publish');
    assert.equal(decision.versions.latest, '2.2');
    assert.equal(decision.refreshRoot, false);
  });

  test('with no final anywhere the newest rc is latest; the first final anywhere takes over', () => {
    const rcs = decide(storeOf('v2.1.0-rc.1'), release('v2.2.0-rc.1'));
    assert.equal(rcs.versions.latest, '2.2');
    assert.equal(rcs.refreshRoot, true);
    const final = decide(rcs.versions, release('v2.1.0'));
    assert.equal(final.versions.latest, '2.1');
    assert.equal(final.refreshRoot, true);
  });

  test('a final older than the stored rc skips, as the store job would refuse it', () => {
    const decision = decide(storeOf('v2.1.1-rc.1'), release('v2.1.0'));
    assert.equal(decision.action, 'skip');
    assert.match(decision.reason, /older than the stored 2\.1\.1-rc\.1/);
  });

  test('copies are sorted newest first, whatever order the store holds', () => {
    const stored = { schema: 1, latest: '2.10', copies: [entry('2.9', '2.9.0'), entry('2.10', '2.10.0')] };
    const decision = decide(stored, release('v2.11.0-rc.1'));
    assert.deepEqual(decision.versions.copies.map((copy) => copy.minor), ['2.11', '2.10', '2.9']);
  });
});

describe('the writer contract', () => {
  test('serialize writes stable key order with a trailing newline', () => {
    const { versions } = decide(null, release('v2.1.0-rc.1'));
    assert.equal(serialize(versions), [
      '{', '  "schema": 1,', '  "latest": "2.1",', '  "copies": [', '    {', '      "minor": "2.1",',
      '      "version": "2.1.0-rc.1",', '      "tag": "v2.1.0-rc.1",', '      "prerelease": true,',
      `      "builtWith": "${TOOLING}"`, '    }', '  ]', '}', '',
    ].join('\n'));
  });

  test('unknown fields of the store survive; only the published entry and latest change', () => {
    const stored = {
      schema: 1, latest: '2.2', copies: [{ ...entry('2.2', '2.2.0'), notice: 'kept' }, entry('2.1', '2.1.0')],
      generator: 'future writer',
    };
    const { versions } = decide(stored, release('v2.1.1'));
    assert.deepEqual(versions.copies[0], { ...entry('2.2', '2.2.0'), notice: 'kept' });
    assert.equal(versions.generator, 'future writer');
    assert.deepEqual(Object.keys(versions), ['schema', 'latest', 'copies', 'generator']);
  });

  test('dev builds and inconsistent release metadata are rejected', () => {
    assert.throws(() => decide(null, { tag: null, builtWith: TOOLING }), /dev builds are never published/);
    assert.throws(() => decide(null, { tag: 'dev', builtWith: TOOLING }), /dev builds are never published/);
    assert.throws(() => decide(null, { ...release('v2.1.0'), minor: 'dev' }), /dev builds are never published/);
    assert.throws(() => decide(null, { ...release('v2.1.0'), prerelease: true }), /disagrees with v2\.1\.0: prerel/);
    assert.throws(() => decide(null, { ...release('v2.1.0-rc.1'), channel: 'stable' }), /channel/);
    assert.throws(() => decide(null, { ...release('v2.1.0'), version: '2.1.1' }), /version/);
    assert.throws(() => decide(null, release('2.1.0')), /not a release tag/);
    assert.throws(() => decide(null, release('v2.1.0', 'main')), /builtWith/);
  });

  test('a malformed or newer store fails loudly instead of being overwritten', () => {
    const valid = storeOf('v2.1.0');
    const broken = [
      { ...valid, schema: 2 },
      { ...valid, latest: '2.2' },
      { ...valid, copies: [] },
      { ...valid, copies: [{ ...entry('2.1', '2.1.0'), tag: 'v2.1.1' }] },
      { ...valid, copies: [{ ...entry('2.1', '2.1.0'), builtWith: 'abc' }] },
      { ...valid, copies: [entry('2.1', '2.1.0'), entry('2.1', '2.1.0')] },
      { ...valid, copies: [entry('2.1', '2.1.0-beta.1')] },
      { schema: 1, latest: '2.0', copies: [entry('2.0', '2.0.0')] },
      { schema: 1, latest: '2.1', copies: [entry('2.2', '2.2.0'), entry('2.1', '2.1.0')] },
      [],
      'versions',
    ];
    for (const stored of broken) {
      assert.throws(() => decide(stored, release('v2.3.0')), /Stored versions\.json/, JSON.stringify(stored));
    }
  });
});
