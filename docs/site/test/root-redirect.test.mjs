// Where /fieldset/latest/ and the root 404 page send a visitor (plan U4, R8): the latest mapping, known and unknown
// minors, and hostile versions.json and path input. FieldsetRedirect.vue only renders this model.
import { describe, test } from 'node:test';
import assert from 'node:assert/strict';
import { pagePathname, redirectModel } from '../lib/root-redirect.mjs';
import { rootVersions } from '../lib/root-stage.mjs';
import { createLoader } from '../lib/theme-versions.mjs';
import { readVersions } from '../lib/versions-reader.mjs';

const ORIGIN = 'https://beyerenrico.github.io';
const entry = (version) => ({
  minor: version.split('.').slice(0, 2).join('.'), version, tag: `v${version}`, prerelease: version.includes('-'),
  builtWith: 'a'.repeat(40),
});
const versionsOf = (latest, ...versions) => ({ schema: 1, latest, copies: versions.map(entry) });
const live = (latest, ...versions) => readVersions(JSON.stringify(versionsOf(latest, ...versions)), null);
const baked = (latest, ...versions) => readVersions(null, rootVersions(versionsOf(latest, ...versions)));
const at = (pathname, read, hash = '') => redirectModel({ pathname, hash }, read);
const okFetch = (body) => async () => ({ ok: true, text: async () => body });

// Every URL of a model, which must stay on this site below /fieldset/.
const urlsOf = (model) => Object.entries(model).filter(([key]) => key.endsWith('Url') || key === 'url')
  .map(([, url]) => url).filter((url) => url !== null);
function assertOnSite(model, label) {
  for (const url of urlsOf(model)) {
    const parsed = new URL(url, `${ORIGIN}/fieldset/latest/x`);
    assert.equal(parsed.origin, ORIGIN, `${label}: ${url}`);
    assert.ok(url.startsWith('/fieldset/') && parsed.pathname.startsWith('/fieldset/'), `${label}: ${url}`);
    assert.doesNotMatch(url, /[\\"'<>\s]|\/\/|%2e|\.\./i, `${label}: ${url}`);
  }
}

describe('/fieldset/latest/', () => {
  test('maps the same path into the latest copy', () => {
    const read = live('2.1', '2.1.0');
    assert.deepEqual(at('/fieldset/latest/install', read), { kind: 'redirect', minor: '2.1', url: '/fieldset/2.1/install' });
    assert.equal(at('/fieldset/latest/', read).url, '/fieldset/2.1/');
    assert.equal(at('/fieldset/latest', read).url, '/fieldset/2.1/');
    assert.equal(at('/fieldset/latest/archive/1.0-install', read).url, '/fieldset/2.1/archive/1.0-install');
    assert.equal(at('/fieldset/latest/api-reference.html', read).url, '/fieldset/2.1/api-reference.html');
  });

  test('keeps a heading fragment and drops anything else in the hash', () => {
    const read = live('2.1', '2.1.0');
    assert.equal(at('/fieldset/latest/install', read, '#mail').url, '/fieldset/2.1/install#mail');
    assert.equal(at('/fieldset/latest/install', read, '#%C3%BCber-uns').url, '/fieldset/2.1/install#%C3%BCber-uns');
    assert.equal(at('/fieldset/latest/install', read, '#"><img src=x onerror=alert(1)>').url, '/fieldset/2.1/install');
  });

  test('follows versions.json latest: the newest final, also while a newer rc exists (AE2)', () => {
    assert.equal(at('/fieldset/latest/install', live('2.1', '2.1.0', '2.2.0-rc.1')).url, '/fieldset/2.1/install');
    assert.equal(at('/fieldset/latest/', live('2.2', '2.1.0', '2.2.0')).url, '/fieldset/2.2/');
    assert.equal(at('/fieldset/latest/', live('2.1', '2.1.0-rc.1')).url, '/fieldset/2.1/');
  });

  test('without any copy it says that nothing is published yet', () => {
    assert.deepEqual(at('/fieldset/latest/install', readVersions(null, null)), {
      kind: 'no-release', homeUrl: '/fieldset/',
    });
  });

  test('before the browser location is known, the page path and the baked versions give a plain link', () => {
    assert.equal(pagePathname('latest/index.md'), '/fieldset/latest/');
    assert.equal(pagePathname('404.md'), '/fieldset/404');
    assert.equal(pagePathname('index.md'), '/fieldset/');
    const model = redirectModel({ pathname: pagePathname('latest/index.md') }, baked('2.1', '2.1.0'));
    assert.deepEqual(model, { kind: 'redirect', minor: '2.1', url: '/fieldset/2.1/' });
  });
});

describe('the 404 page', () => {
  test('a missing page of a known minor targets that copy index', () => {
    const read = live('2.1', '2.1.0', '2.2.0-rc.1');
    assert.deepEqual(at('/fieldset/2.1/no-such-page', read), { kind: 'redirect', minor: '2.1', url: '/fieldset/2.1/' });
    assert.equal(at('/fieldset/2.2/archive/gone', read).url, '/fieldset/2.2/');
    assert.equal(at('/fieldset/2.1/no-such-page', baked('2.1', '2.1.0')).url, '/fieldset/2.1/');
  });

  test('a missing copy index is not found instead of a redirect loop', () => {
    assert.equal(at('/fieldset/2.1/', live('2.1', '2.1.0')).kind, 'not-found');
  });

  test('/fieldset/2.0/ and /fieldset/1.0/install were never published', () => {
    const expected = (version) => ({
      kind: 'never-published', version, latestUrl: '/fieldset/2.1/', archiveUrl: '/fieldset/2.1/archive/1.0-install',
      homeUrl: '/fieldset/',
    });
    for (const read of [live('2.1', '2.1.0'), baked('2.1', '2.1.0')]) {
      assert.deepEqual(at('/fieldset/2.0/', read), expected('2.0'));
      assert.deepEqual(at('/fieldset/1.0/install', read), expected('1.0'));
    }
  });

  test('a later unknown minor is never-published only by the live versions.json', () => {
    assert.equal(at('/fieldset/2.9/', live('2.1', '2.1.0')).kind, 'never-published');
    // The root was built before 2.9 was published and versions.json could not be read: no false claim.
    assert.deepEqual(at('/fieldset/2.9/install', baked('2.1', '2.1.0')), {
      kind: 'not-found', latestUrl: '/fieldset/2.1/', homeUrl: '/fieldset/',
    });
    assert.deepEqual(at('/fieldset/2.0/', readVersions(null, null)), {
      kind: 'never-published', version: '2.0', latestUrl: null, archiveUrl: null, homeUrl: '/fieldset/',
    });
  });

  test('other missing paths are not found, linking the latest copy and home', () => {
    const read = live('2.1', '2.1.0');
    for (const path of ['/fieldset/spec/form-definition/2.0/', '/fieldset/dev/', '/fieldset/2.1.0/', '/fieldset/x']) {
      assert.deepEqual(at(path, read), { kind: 'not-found', latestUrl: '/fieldset/2.1/', homeUrl: '/fieldset/' }, path);
    }
    assert.deepEqual(at('/fieldset/x', readVersions(null, null)), {
      kind: 'not-found', latestUrl: '/fieldset/latest/', homeUrl: '/fieldset/',
    });
  });
});

describe('hostile input', () => {
  const HOSTILE_VERSIONS = [
    { schema: 1, latest: 'javascript:alert(1)', copies: [entry('2.1.0')] },
    { schema: 1, latest: '//evil.example', copies: [{ ...entry('2.1.0'), minor: '//evil.example' }] },
    { schema: 1, latest: '2.1', copies: [{ ...entry('2.1.0'), version: '<img src=x onerror=alert(1)>' }] },
    { schema: 1, latest: '2.1', copies: [entry('2.1.0'), { ...entry('2.2.0'), minor: '2.2/../../evil' }] },
    { schema: 2, latest: '9.9', copies: [entry('9.9.0')] },
  ];
  const PATHS = [
    '/fieldset/latest/', '/fieldset/latest/install', '/fieldset/2.1/missing', '/fieldset/2.0/', '/fieldset/9.9/',
    '/fieldset/evil/',
  ];

  test('a hostile versions.json sends latest/ and 404 only to /fieldset/ paths', async () => {
    for (const [index, hostile] of HOSTILE_VERSIONS.entries()) {
      for (const fallback of [rootVersions(versionsOf('2.1', '2.1.0')), null]) {
        const read = await createLoader(fallback, okFetch(JSON.stringify(hostile)))();
        assert.equal(read.fromBaked, true, `versions ${index}`);
        for (const path of PATHS) assertOnSite(at(path, read, '#x'), `versions ${index}, ${path}`);
      }
    }
  });

  test('encoded dots, double slashes, backslashes and schemes in the path stay on /fieldset/', () => {
    const read = live('2.1', '2.1.0');
    const paths = [
      '/fieldset/latest/%2e%2e/%2e%2e/evil', '/fieldset/latest/..%2F..%2Fevil', '/fieldset/latest//evil.example/x',
      '/fieldset/latest/\\evil.example', '/fieldset/latest/%5Cevil.example', '/fieldset/latest/https:/evil.example',
      '/fieldset/latest/javascript:alert(1)', '/fieldset/latest/.hidden', '/fieldset/latest/a/../../evil',
    ];
    for (const path of paths) {
      assert.deepEqual(at(path, read, '#x'), { kind: 'redirect', minor: '2.1', url: '/fieldset/2.1/#x' }, path);
    }
    for (const path of ['/fieldset/%2e%2e/', '/fieldset/2.1%2F..%2F/', '//evil.example/fieldset/latest/', '/x/latest/']) {
      const model = at(path, read);
      assert.equal(model.kind, 'not-found', path);
      assertOnSite(model, path);
    }
    assert.equal(at(undefined, read).kind, 'not-found');
  });
});
