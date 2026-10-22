// The browser reader of /fieldset/versions.json (plan U5; KTDs "versions.json schema 1 is frozen" and "Clients treat
// versions.json as hostile"): it validates every field it uses, ignores unknown ones, falls back to the baked values,
// and builds only /fieldset/ URLs. The frozen copy of the first released reader must parse the current writer's output.
import { describe, test } from 'node:test';
import assert from 'node:assert/strict';
import * as frozen from './fixtures/versions-reader-v1.mjs';
import { copyUrl, LATEST_URL, readVersions, SITE_ROOT, VERSIONS_URL } from '../lib/versions-reader.mjs';
import { TOOLING, WRITER_OUTPUTS } from './support/versions-scenarios.mjs';

const copy = (minor, version) => ({ minor, version, tag: `v${version}`, prerelease: version.includes('-') });
const LIVE = {
  schema: 1, latest: '2.1',
  copies: [{ ...copy('2.1', '2.1.0'), builtWith: TOOLING }, { ...copy('2.2', '2.2.0-rc.1'), builtWith: TOOLING }],
};
const BAKED = { schema: 1, latest: '2.1', copies: [copy('2.1', '2.1.0-rc.1')] };
const BAKED_MODEL = {
  latest: '2.1', copies: [{ ...copy('2.1', '2.1.0-rc.1'), url: '/fieldset/2.1/' }], fromBaked: true,
};

// Every URL and every string a model can produce, for the "never outside /fieldset/" and plain-text assertions.
const urlsOf = (model) => model.copies.map((entry) => entry.url);
const stringsOf = (model) => [
  model.latest, ...model.copies.flatMap((entry) => [entry.minor, entry.version, entry.tag]),
];

describe('reading a valid file', () => {
  test('the model lists the copies newest first with their URLs and pre-release marks', () => {
    assert.deepEqual(readVersions(JSON.stringify(LIVE), BAKED), {
      latest: '2.1',
      copies: [
        { ...copy('2.2', '2.2.0-rc.1'), url: '/fieldset/2.2/' }, { ...copy('2.1', '2.1.0'), url: '/fieldset/2.1/' },
      ],
      fromBaked: false,
    });
  });

  test('a parsed object reads like its JSON text, and unknown fields are ignored', () => {
    const extended = {
      ...LIVE, generator: 'x', copies: LIVE.copies.map((entry) => ({ ...entry, notice: '<b>new</b>' })),
    };
    assert.deepEqual(readVersions(extended, BAKED), readVersions(JSON.stringify(LIVE), BAKED));
  });

  test('the fetch path is literal and the fixed URLs stay under /fieldset/', () => {
    assert.equal(VERSIONS_URL, '/fieldset/versions.json');
    assert.equal(LATEST_URL, '/fieldset/latest/');
    assert.equal(SITE_ROOT, '/fieldset/');
  });
});

describe('falling back to the baked values', () => {
  const fallbacks = {
    'malformed JSON': '{"schema": 1, "latest": ',
    'schema 2': { ...LIVE, schema: 2 },
    'schema as a string': { ...LIVE, schema: '1' },
    'no schema': { latest: LIVE.latest, copies: LIVE.copies },
    'not an object': [LIVE],
    'null': null,
    'no copies': { ...LIVE, copies: [] },
    'copies not a list': { ...LIVE, copies: { 0: LIVE.copies[0] } },
    'latest not a copy': { ...LIVE, latest: '2.3' },
    'duplicate minor': { ...LIVE, copies: [LIVE.copies[0], LIVE.copies[0]] },
    'minor and version disagree': { ...LIVE, copies: [{ ...copy('2.1', '2.1.0'), minor: '2.2' }] },
    'tag and version disagree': { ...LIVE, copies: [{ ...copy('2.1', '2.1.0'), tag: 'v2.1.1' }] },
    'prerelease not a boolean': { ...LIVE, copies: [{ ...copy('2.1', '2.1.0'), prerelease: 'false' }] },
    'prerelease wrong': { ...LIVE, copies: [{ ...copy('2.1', '2.1.0'), prerelease: true }] },
    'minor as a list': { ...LIVE, copies: [{ ...copy('2.1', '2.1.0'), minor: ['2.1'] }] },
    'inherited fields': { ...LIVE, copies: [Object.create(copy('2.1', '2.1.0'))] },
  };
  for (const [name, input] of Object.entries(fallbacks)) {
    test(`${name} yields the baked model`, () => assert.deepEqual(readVersions(input, BAKED), BAKED_MODEL));
  }

  test('an invalid baked value yields an empty model, never an exception', () => {
    assert.deepEqual(readVersions('nope', { schema: 1 }), { latest: null, copies: [], fromBaked: true });
    assert.deepEqual(readVersions(undefined, undefined), { latest: null, copies: [], fromBaked: true });
  });
});

describe('a hostile file', () => {
  const hostile = {
    'latest as a javascript: URL': { ...LIVE, latest: 'javascript:alert(1)' },
    'a protocol-relative minor': {
      ...LIVE, latest: '//evil.example', copies: [{ ...copy('2.1', '2.1.0'), minor: '//evil.example' }],
    },
    'a minor with a path': { ...LIVE, copies: [{ ...LIVE.copies[0], minor: '2.1/../../evil' }] },
    'an HTML version': { ...LIVE, copies: [{ ...LIVE.copies[0], version: '<img src=x onerror=alert(1)>' }] },
    'an HTML tag': { ...LIVE, copies: [{ ...LIVE.copies[0], tag: 'v2.1.0<script>' }] },
  };
  for (const [name, input] of Object.entries(hostile)) {
    test(`${name} produces no link outside /fieldset/`, () => {
      const model = readVersions(input, BAKED);
      assert.deepEqual(model, BAKED_MODEL);
      for (const url of urlsOf(model)) assert.match(url, /^\/fieldset\/\d+\.\d+\/$/);
    });
  }

  test('an HTML label field is ignored: every string of the model is plain text', () => {
    const model = readVersions({ ...LIVE, copies: LIVE.copies.map((entry) => ({ ...entry, label: '<a href=//x>' })) });
    assert.equal(model.fromBaked, false);
    for (const text of stringsOf(model)) assert.match(text, /^v?[\w.-]+$/);
    for (const url of urlsOf(model)) assert.match(url, /^\/fieldset\/\d+\.\d+\/$/);
  });
});

describe('copyUrl', () => {
  test('builds paths inside a copy from a valid minor', () => {
    assert.equal(copyUrl('2.1'), '/fieldset/2.1/');
    assert.equal(copyUrl('2.1', 'install'), '/fieldset/2.1/install');
    assert.equal(copyUrl('10.12', 'archive/1.0-upgrade'), '/fieldset/10.12/archive/1.0-upgrade');
    assert.equal(copyUrl('2.1', 'archive/'), '/fieldset/2.1/archive/');
    assert.equal(copyUrl('2.1', 'api-reference.html'), '/fieldset/2.1/api-reference.html');
  });

  test('an invalid minor goes home; an unsafe path goes to the copy index', () => {
    const minors = ['dev', 'latest', '//evil.example', 'javascript:alert(1)', '2.1/..', ' 2.1', '2.1\n', 2.1, null];
    for (const minor of minors) assert.equal(copyUrl(minor, 'install'), SITE_ROOT, JSON.stringify(minor));
    for (const path of ['../other', '/abs', '//evil.example', 'a//b', './x', 'a/../b', '%2e%2e/x', 'https://x',
      'x?y=1', 'x#y', 'x\ny', '\\evil', null, ['install']]) {
      assert.equal(copyUrl('2.1', path), '/fieldset/2.1/', JSON.stringify(path));
    }
  });
});

describe('the frozen v1 reader', () => {
  test('parses every writer output as the current reader does', () => {
    for (const [name, versions] of WRITER_OUTPUTS) {
      const model = frozen.readVersions(JSON.stringify(versions), BAKED);
      assert.equal(model.fromBaked, false, name);
      assert.deepEqual(model, readVersions(JSON.stringify(versions), BAKED), name);
    }
  });

  test('ignores a future extra field at the top and in every copy', () => {
    for (const [name, versions] of WRITER_OUTPUTS) {
      const copies = versions.copies.map((entry) => ({ ...entry, eol: '2030' }));
      const future = { ...versions, channels: ['lts'], copies };
      assert.deepEqual(frozen.readVersions(JSON.stringify(future), BAKED), frozen.readVersions(versions, BAKED), name);
    }
  });
});
