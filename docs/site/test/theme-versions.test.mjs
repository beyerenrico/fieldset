// The version copy's theme logic (plan U3, R6, R7, R20): the switcher model, the notices and the versions.json loader.
// The .vue components only render these models, so every U3 scenario is proven here.
import { describe, test } from 'node:test';
import assert from 'node:assert/strict';
import { readVersions, VERSIONS_URL } from '../lib/versions-reader.mjs';
import {
  bakedVersions, createVersionsLoader, installCommand, notices, switcherModel,
} from '../lib/theme-versions.mjs';

const REPOSITORY = 'beyerenrico/fieldset';
const copyOf = (version, extra = {}) => ({
  minor: version.split('.').slice(0, 2).join('.'), version, tag: `v${version}`, prerelease: version.includes('-'),
  repository: REPOSITORY, ref: `v${version}`, ...extra,
});
const DEV = { ...copyOf('2.1.0-rc.1'), minor: 'dev', tag: null, ref: 'c'.repeat(40) };
const entry = (version) => ({ ...copyOf(version), builtWith: 'a'.repeat(40) });
const versionsJson = (latest, ...versions) => JSON.stringify({ schema: 1, latest, copies: versions.map(entry) });
const read = (text, copy) => readVersions(text, bakedVersions(copy));

const okFetch = (body) => async () => ({ ok: true, text: async () => body });
const kinds = (list) => list.map((notice) => notice.kind);

describe('the version switcher', () => {
  test('lists every copy newest first, marks pre-releases and links each copy index', () => {
    const copy = copyOf('2.1.0');
    const model = switcherModel(copy, read(versionsJson('2.1', '2.1.0', '2.2.0-rc.1'), copy));
    assert.equal(model.label, '2.1.0');
    assert.equal(model.home, '/fieldset/');
    assert.deepEqual(model.entries, [
      { minor: '2.2', version: '2.2.0-rc.1', prerelease: true, latest: false, current: false, url: '/fieldset/2.2/' },
      { minor: '2.1', version: '2.1.0', prerelease: false, latest: true, current: true, url: '/fieldset/2.1/' },
    ]);
  });

  test('before the fetch it shows the current version only', () => {
    const model = switcherModel(copyOf('2.1.0-rc.1'), null);
    assert.equal(model.label, '2.1.0-rc.1');
    assert.equal(model.prerelease, true);
    assert.deepEqual(model.entries.map((item) => [item.version, item.current, item.url]), [
      ['2.1.0-rc.1', true, '/fieldset/2.1/'],
    ]);
  });

  test('a failed fetch yields only the current version and home', async () => {
    const copy = copyOf('2.1.0');
    const failures = {
      'a network error': async () => { throw new TypeError('Failed to fetch'); },
      'a 404': async () => ({ ok: false, status: 404, text: async () => 'Not found' }),
      'malformed JSON': okFetch('{"schema": 1, "latest": '),
      'schema 2': okFetch(JSON.stringify({ schema: 2, latest: '2.2', copies: [entry('2.2.0')] })),
    };
    for (const [name, fetchImpl] of Object.entries(failures)) {
      const model = switcherModel(copy, await createVersionsLoader(copy, fetchImpl)());
      assert.deepEqual(model.entries, [
        { minor: '2.1', version: '2.1.0', prerelease: false, latest: true, current: true, url: '/fieldset/2.1/' },
      ], name);
      assert.equal(model.home, '/fieldset/', name);
    }
  });

  test('a hostile file yields only /fieldset/ links and plain-text labels', () => {
    const copy = copyOf('2.1.0');
    const hostile = JSON.stringify({
      schema: 1, latest: 'javascript:alert(1)',
      copies: [{ ...entry('2.1.0'), minor: '//evil.example', label: '<img src=x onerror=alert(1)>' }],
    });
    const model = switcherModel(copy, read(hostile, copy));
    for (const item of model.entries) {
      assert.match(item.url, /^\/fieldset\/\d+\.\d+\/$/);
      assert.match(item.version, /^[\w.-]+$/);
    }
    assert.deepEqual(model.entries.map((item) => item.version), ['2.1.0']);
  });

  test('a working-tree build is listed as dev, first, without a link', () => {
    const model = switcherModel(DEV, read(versionsJson('2.1', '2.1.0'), DEV));
    assert.equal(model.label, 'dev');
    assert.deepEqual(model.entries.map((item) => [item.version, item.current, item.url]), [
      ['dev', true, null], ['2.1.0', false, '/fieldset/2.1/'],
    ]);
  });
});

describe('the notices', () => {
  const COMMAND = 'curl --proto \'=https\' --tlsv1.2 -fsSL '
    + 'https://github.com/beyerenrico/fieldset/releases/download/v2.1.0-rc.1/install.sh '
    + '| sh -s -- --version 2.1.0-rc.1 --org "Example Ltd" --owner-email owner@example.com';

  test('AE1: a copy built from v2.1.0-rc.1 shows the pre-release notice with its exact install command', () => {
    const copy = copyOf('2.1.0-rc.1');
    assert.deepEqual(notices(copy, null), [{ kind: 'prerelease', version: '2.1.0-rc.1', command: COMMAND }]);
    assert.equal(installCommand(copy), COMMAND);
  });

  test('a 2.1 copy shows the newer-version notice when latest is 2.2', () => {
    const copy = copyOf('2.1.0');
    assert.deepEqual(notices(copy, read(versionsJson('2.2', '2.1.0', '2.2.0'), copy)), [
      { kind: 'newer', version: '2.2.0', minor: '2.2', url: '/fieldset/2.2/' },
    ]);
  });

  test('a 2.1 copy shows none when latest is 2.1, also while 2.2-rc.1 exists', () => {
    const copy = copyOf('2.1.0');
    assert.deepEqual(notices(copy, read(versionsJson('2.1', '2.1.0'), copy)), []);
    assert.deepEqual(notices(copy, read(versionsJson('2.1', '2.1.0', '2.2.0-rc.1'), copy)), []);
  });

  test('an rc copy that is also latest (no final anywhere) shows only the pre-release notice', () => {
    const copy = copyOf('2.2.0-rc.1');
    assert.deepEqual(kinds(notices(copy, read(versionsJson('2.2', '2.1.0-rc.1', '2.2.0-rc.1'), copy))), ['prerelease']);
  });

  test('an rc copy older than a newer final shows both notices', () => {
    const copy = copyOf('2.1.0-rc.1');
    assert.deepEqual(kinds(notices(copy, read(versionsJson('2.2', '2.1.0-rc.1', '2.2.0'), copy))), [
      'prerelease', 'newer',
    ]);
  });

  test('a failed or hostile fetch shows no newer-version notice', async () => {
    const copy = copyOf('2.1.0');
    const failed = await createVersionsLoader(copy, async () => { throw new Error('offline'); })();
    assert.deepEqual(notices(copy, failed), []);
    const hostile = JSON.stringify({ schema: 1, latest: '2.9', copies: [{ ...entry('2.9.0'), minor: '2.9/../x' }] });
    assert.deepEqual(notices(copy, read(hostile, copy)), []);
  });

  test('a working-tree build shows a development notice: no install command, no newer-version notice', () => {
    const list = notices(DEV, read(versionsJson('2.2', '2.2.0'), DEV));
    assert.deepEqual(list, [{ kind: 'development', ref: 'c'.repeat(12), url: '/fieldset/latest/' }]);
    assert.equal(installCommand(DEV), null);
  });
});

describe('the versions.json loader', () => {
  test('fetches the literal /fieldset/versions.json once per page load, without credentials', async () => {
    const calls = [];
    const fetchImpl = async (...args) => { calls.push(args); return okFetch(versionsJson('2.1', '2.1.0'))(); };
    const load = createVersionsLoader(copyOf('2.1.0'), fetchImpl);
    const [first, second] = await Promise.all([load(), load()]);
    assert.equal(first, second);
    assert.equal(first.fromBaked, false);
    assert.equal(calls.length, 1);
    assert.equal(calls[0][0], VERSIONS_URL);
    assert.equal(calls[0][1].credentials, 'omit');
  });

  test('the baked fallback is this copy alone, and nothing for a working-tree build', () => {
    assert.deepEqual(bakedVersions(copyOf('2.1.0-rc.1')), {
      schema: 1, latest: '2.1', copies: [{ minor: '2.1', version: '2.1.0-rc.1', tag: 'v2.1.0-rc.1', prerelease: true }],
    });
    assert.equal(bakedVersions(DEV), null);
  });
});
