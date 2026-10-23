// The root home page's data (plan U4, R8, R11): the newest release's install command, the documentation links and the
// screenshots of the page's front matter. HomeStart.vue and HomeMore.vue only render these models.
import { describe, test } from 'node:test';
import assert from 'node:assert/strict';
import {
  bakedRead, docsUrls, homeActions, homeDocs, homeModel, homeScreenshots, installModel, LINK_TARGETS,
} from '../lib/root-home.mjs';
import { rootVersions } from '../lib/root-stage.mjs';
import { decide } from '../lib/versions.mjs';

const REPOSITORY = 'beyerenrico/fieldset';
const FLAGS = '--org "Example Ltd" --owner-email owner@example.com';
const storeOf = (...tags) => tags.reduce((stored, tag) => decide(stored, { tag, builtWith: 'a'.repeat(40) }).versions, null);
const commandFor = (version) => `curl --proto '=https' --tlsv1.2 -fsSL https://github.com/${REPOSITORY}/releases/download/`
  + `v${version}/install.sh | sh -s -- --version ${version} ${FLAGS}`;
const urlsFor = (...tags) => docsUrls(bakedRead(rootVersions(storeOf(...tags))));

describe('the install command', () => {
  test('with no final release it is the rc command, labelled pre-release', () => {
    const { install } = homeModel(rootVersions(storeOf('v2.1.0-rc.1')), REPOSITORY);
    assert.deepEqual(install, { version: '2.1.0-rc.1', prerelease: true, command: commandFor('2.1.0-rc.1') });
  });

  test('it installs the newest final, also while a newer rc exists (AE2)', () => {
    const { install } = homeModel(rootVersions(storeOf('v2.1.0', 'v2.2.0-rc.1')), REPOSITORY);
    assert.deepEqual(install, { version: '2.1.0', prerelease: false, command: commandFor('2.1.0') });
    assert.equal(homeModel(rootVersions(storeOf('v2.1.0', 'v2.2.0', 'v2.1.1')), REPOSITORY).install.version, '2.2.0');
  });

  test('the command uses install.md flags and the version twice, as the versioned install requires', () => {
    const { command } = homeModel(rootVersions(storeOf('v2.1.0-rc.1', 'v2.1.0')), REPOSITORY).install;
    assert.ok(command.endsWith(`| sh -s -- --version 2.1.0 ${FLAGS}`), command);
    assert.match(command, /\/releases\/download\/v2\.1\.0\/install\.sh /);
  });

  test('before any release there is none', () => {
    assert.equal(homeModel(null, REPOSITORY).install, null);
    assert.equal(installModel(bakedRead(rootVersions(storeOf('v2.1.0'))), 'not a repository'), null);
  });
});

describe('the documentation links', () => {
  test('lead into the latest copy, and to the spec of the root', () => {
    assert.deepEqual(urlsFor('v2.1.0', 'v2.2.0-rc.1'), {
      guides: '/fieldset/2.1/', install: '/fieldset/2.1/install', integrations: '/fieldset/2.1/api',
      'api-contract': '/fieldset/2.1/field-app-api-v1', 'api-reference': '/fieldset/2.1/api-reference.html',
      changelog: '/fieldset/2.1/changelog', spec: '/fieldset/spec/form-definition/1.0/',
    });
    assert.deepEqual(Object.keys(urlsFor('v2.1.0')), LINK_TARGETS);
  });

  test('go through /fieldset/latest/ while no release is known', () => {
    const urls = homeModel(null, REPOSITORY).urls;
    assert.equal(urls.guides, '/fieldset/latest/');
    assert.equal(urls.changelog, '/fieldset/latest/changelog');
    assert.equal(urls.spec, '/fieldset/spec/form-definition/1.0/');
  });

  test('front matter actions and docs name targets and get URLs', () => {
    const urls = urlsFor('v2.1.0-rc.1');
    assert.deepEqual(homeActions([{ text: 'Read the guides', to: 'guides' }, { text: 'Spec', to: 'spec', theme: 'alt' }], urls), [
      { text: 'Read the guides', url: '/fieldset/2.1/', theme: 'brand' },
      { text: 'Spec', url: '/fieldset/spec/form-definition/1.0/', theme: 'alt' },
    ]);
    assert.deepEqual(homeDocs([{ to: 'changelog', title: 'Changelog', details: 'What changed.' }], urls), [
      { title: 'Changelog', details: 'What changed.', url: '/fieldset/2.1/changelog' },
    ]);
    assert.deepEqual(homeDocs(undefined, urls), []);
  });

  test('front matter can name only known targets, never a URL', () => {
    const urls = urlsFor('v2.1.0');
    const bad = [
      [{ to: 'https://evil.example', title: 'x', details: 'y' }, /docs\[0\] links to "https:\/\/evil\.example"/],
      [{ to: '/fieldset/2.1/', title: 'x', details: 'y' }, /not to guides, install/],
      [{ to: 'toString', title: 'x', details: 'y' }, /links to "toString"/],
      [{ to: '__proto__', title: 'x', details: 'y' }, /links to "__proto__"/],
      [{ to: 'guides', title: '', details: 'y' }, /docs\[0\] needs a non-empty title/],
      [{ to: 'guides', title: 'x' }, /docs\[0\] needs a non-empty details/],
    ];
    for (const [entry, message] of bad) assert.throws(() => homeDocs([entry], urls), message);
    assert.throws(() => homeActions([{ text: 'x', to: 'guides', theme: 'danger' }], urls), /theme other than brand, alt/);
    assert.throws(() => homeActions({ to: 'guides' }, urls), /actions must be a list/);
  });
});

describe('the screenshots', () => {
  test('an empty or missing list shows none', () => {
    assert.deepEqual(homeScreenshots([]), []);
    assert.deepEqual(homeScreenshots(undefined), []);
  });

  test('entries become /fieldset/screenshots/ images with alt text', () => {
    assert.deepEqual(homeScreenshots([
      { src: 'screenshots/forms-list.png', alt: 'The forms list', caption: 'Forms' },
      { src: 'screenshots/field-app.webp', alt: 'The Field App' },
    ]), [
      { url: '/fieldset/screenshots/forms-list.png', alt: 'The forms list', caption: 'Forms' },
      { url: '/fieldset/screenshots/field-app.webp', alt: 'The Field App', caption: null },
    ]);
  });

  test('a source outside screenshots/, another type or a missing alt fails', () => {
    for (const src of ['../x.png', 'screenshots/../x.png', 'https://evil.example/x.png', 'screenshots/x.svg',
      '/screenshots/x.png', 'screenshots/sub/x.png', 'screenshots/.x.png']) {
      assert.throws(() => homeScreenshots([{ src, alt: 'x' }]), /src must be screenshots\/<name>/, src);
    }
    assert.throws(() => homeScreenshots([{ src: 'screenshots/x.png' }]), /screenshots\[0\] needs a non-empty alt/);
  });
});
