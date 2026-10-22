// The root 404.html's static fallback (lib/root-fallback.mjs, plan U4): added once outside #app, and a page without the
// markers fails the build instead of dropping the fallback silently.
import { describe, test } from 'node:test';
import assert from 'node:assert/strict';
import { FALLBACK_CLASS, FALLBACK_HTML, withStaticFallback } from '../lib/root-fallback.mjs';

const page = (head = '</head>', app = '<div id="app"></div>') => `<!doctype html><html><head><title>404</title>${head}`
  + `<body>${app}<script type="module" src="/fieldset/assets/app.js"></script></body></html>`;

describe('withStaticFallback', () => {
  test('adds the links before #app and the style that hides them into the head', () => {
    const html = withStaticFallback(page());
    assert.equal(html.split(FALLBACK_HTML).length, 2);
    assert.ok(html.indexOf(FALLBACK_HTML) < html.indexOf('<div id="app">'));
    assert.ok(html.indexOf(`.${FALLBACK_CLASS}{`) < html.indexOf('</head>'));
  });

  test('returns a page that already has the fallback unchanged', () => {
    const once = withStaticFallback(page());
    assert.equal(withStaticFallback(once), once);
  });

  test('throws when the page has no #app container or no </head>', () => {
    const missing = /404\.html has no #app container or <\/head>/;
    assert.throws(() => withStaticFallback(page('</head>', '<main></main>')), missing);
    assert.throws(() => withStaticFallback(page('')), missing);
    assert.throws(() => withStaticFallback(''), missing);
  });
});
