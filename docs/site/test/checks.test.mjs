// The post-build checks (plan U12, R5, R13, R15, R22) on a fixture output shaped like a dev build: a copy at dev/ and
// the root around it. The valid fixture passes; each seeded defect fails naming the check, the file and the detail.
import { after, describe, test } from 'node:test';
import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import { createHash } from 'node:crypto';
import { copyFileSync, readFileSync, rmSync, symlinkSync, writeFileSync } from 'node:fs';
import { join } from 'node:path';
import { assertChecks, CSP_META, DEFAULT_LITERALS, formatFailures, runChecks } from '../lib/checks.mjs';
import { prepareDocument, REFERENCE_CSP, REQUIRED_PATHS, referencePage, scalarBundle } from '../lib/openapi.mjs';
import { withStaticFallback } from '../lib/root-fallback.mjs';
import { cleanup, tempDir, writeTree } from './support/site.mjs';

after(cleanup);
const SPEC = 'spec/form-definition/1.0';
const META_SCHEMA = '{\n    "$id": "https://beyerenrico.github.io/fieldset/spec/form-definition/1.0/meta-schema.json"'
  + '\n}\n';
const ROLES = '{"roles": []}\n';

const page = (body) => '<!DOCTYPE html>\n<html lang="en-US" dir="ltr">\n  <head>\n    <meta charset="utf-8">\n'
  + '    <title>Fixture | Fieldset</title>\n    <script id="check-dark-mode">(()=>{})();</script>\n  </head>\n'
  + `  <body>\n    <div id="app"><div class="Layout"><a href="#VPContent">Skip</a><main id="VPContent">${body}</main>`
  + '</div></div>\n  </body>\n</html>\n';

const exported = {
  openapi: '3.1.0', info: { title: 'Fieldset API v1', version: 'dev' }, servers: [{ url: 'http://localhost/api/v1' }],
  paths: Object.fromEntries(REQUIRED_PATHS.map((path) => [path,
    { get: { responses: { 200: { description: 'OK' } } } }])),
};
const REFERENCE = referencePage({ document: prepareDocument(exported), descriptor: { minor: 'dev' },
  integrity: scalarBundle().integrity });

const PAGES = {
  'dev/index.html': page('<h1 id="guides">Guides<a class="header-anchor" href="#guides">#</a></h1>'
    + '<a href="/fieldset/dev/install#mail">mail</a><a href="install#gr%C3%B6%C3%9Fe">umlaut</a>'
    + '<a href="./archive/1.0-install#adopt-a-10-install">adopt</a>'
    + '<a href="../spec/form-definition/1.0/#fieldset-spec-10" target="_self">spec</a>'
    + '<a href="https://example.com/install#mail">external</a><a href="/fieldset/2.2/install#mail">2.2</a>'
    + '<a href="/fieldset/latest/install#mail">latest</a><a href="../spec/form-definition/1.0/meta-schema.json#/x">'
    + 'pointer</a><a href="/fieldset/dev/api-reference.html" target="_self">API</a>'),
  'dev/install.html': page('<h1 id="install-guide">Install</h1><h2 id="mail">Mail</h2><h2 id="größe">Größe</h2>'),
  'dev/archive/1.0-install.html': page('<h2 id="adopt-a-10-install">Adopt</h2><a href="../install#mail">x</a>'),
  'dev/field-app-guide.html': page('<p>Choose "Weiter mit <code>&lt;organization&gt;</code>" ("Continue with\n'
    + '&lt;organization&gt;")</p>'),
  'dev/troubleshooting.html': page('<p>"Single Sign-on erfordert Ihre Aufmerksamkeit: &lt;provider&gt;"</p>'),
  'dev/archive/1.0-troubleshooting.html': page('<p>"Single Sign-on erfordert Ihre\nAufmerksamkeit: &lt;provider&gt;"'),
  'dev/field-app-api-v1.html': page('<p>the client offers "Continue with &lt;organization&gt;"</p>'),
  'dev/admin-guide.html': page('<p>rich text with the tokens <code>{{org.name}}</code>,</p>'),
  'dev/404.html': page('<a href="/fieldset/dev/">home</a>'),
  'dev/api-reference.html': REFERENCE,
  'index.html': page('<img src="/fieldset/screenshots/forms-list.png" alt="Forms" loading="lazy">'
    + '<a href="/fieldset/spec/form-definition/1.0/#fieldset-spec-10">spec</a>'),
  '404.html': withStaticFallback(page('').replace(/<div id="app">[\s\S]*<\/div><\/div>/, '<div id="app"></div>')),
  'latest/index.html': page('<a href="/fieldset/dev/" target="_self">Continue</a>'),
  [`${SPEC}/index.html`]: page('<h1 id="fieldset-spec-10">Fieldset Spec 1.0</h1>'),
  [`${SPEC}/meta-schema.json`]: META_SCHEMA,
  [`${SPEC}/roles.json`]: ROLES,
  'screenshots/forms-list.png': 'png bytes',
};

function fixture(overrides = {}) {
  const outDir = writeTree(tempDir('fieldset-checks-'), PAGES);
  copyFileSync(scalarBundle().path, join(outDir, 'dev/scalar-api-reference.js'));
  const specSourceDir = writeTree(tempDir('fieldset-spec-'), { 'meta-schema.json': META_SCHEMA, 'roles.json': ROLES });
  const options = { outDir, copy: 'dev', root: true, mode: 'dev', specSourceDir, ...overrides };
  const path = (file) => join(outDir, file);
  const edit = (file, change) => writeFileSync(path(file), change(readFileSync(path(file), 'utf8')));
  return { outDir, specSourceDir, options, path, edit, run: () => runChecks(options) };
}

const only = (result, check) => result.failures.filter((failure) => failure.check === check);
function assertFails(result, check, file, detail) {
  const failures = result.failures.filter((failure) => failure.check === check && failure.file === file
    && detail.test(failure.detail));
  assert.equal(failures.length, 1, `expected one ${check} failure for ${file} matching ${detail}, got:\n`
    + `${formatFailures(result.failures)}`);
}

describe('a valid output', () => {
  test('passes every check, and the checks saw what they check', () => {
    const result = fixture().run();
    assert.deepEqual(result.failures, []);
    assert.deepEqual(result.skipped, []);
    assert.deepEqual(result.counts, { files: 18, fragments: 19, literals: 6, spec: 2, screenshots: 1, fallback: 1,
      reference: 1, fragmentsSkipped: 3 });
  });

  test('a copy alone passes; root checks and links into the root are skipped', () => {
    const { outDir, run } = fixture({ root: false });
    for (const file of ['index.html', '404.html', 'latest', 'spec', 'screenshots']) {
      rmSync(join(outDir, file), { recursive: true });
    }
    const result = run();
    assert.deepEqual(result.failures, []);
    assert.deepEqual(result.skipped, ['spec', 'screenshots', 'fallback']);
    assert.equal(result.counts.fragmentsSkipped, 4);
  });

  test('a home page without screenshots and no screenshots/ dir is valid', () => {
    const { edit, outDir, run } = fixture();
    edit('index.html', (html) => html.replace(/<img[^>]*>/, ''));
    rmSync(join(outDir, 'screenshots'), { recursive: true });
    assert.deepEqual(only(run(), 'screenshots'), []);
  });
});

describe('fragments', () => {
  test('a link to a heading that does not exist fails naming page and fragment', () => {
    const { edit, run } = fixture();
    edit('dev/index.html', (html) => html.replace('install#mail', 'install#no-such-heading'));
    assertFails(run(), 'fragments', 'dev/index.html',
      /^link "\/fieldset\/dev\/install#no-such-heading": dev\/install\.html has no element with id "no-such-heading"$/);
  });

  test('same-page, archive-relative and cross-build fragments are checked', () => {
    const { edit, run } = fixture();
    edit('dev/index.html', (html) => html.replace('href="#guides"', 'href="#guide"')
      .replace('1.0/#fieldset-spec-10', '1.0/#fieldset-spec'));
    edit('dev/archive/1.0-install.html', (html) => html.replace('../install#mail', '../install#mails'));
    const result = run();
    assertFails(result, 'fragments', 'dev/index.html', /dev\/index\.html has no element with id "guide"$/);
    assertFails(result, 'fragments', 'dev/index.html',
      /spec\/form-definition\/1\.0\/index\.html has no .*"fieldset-spec"$/);
    assertFails(result, 'fragments', 'dev/archive/1.0-install.html', /"\.\.\/install#mails": dev\/install\.html/);
    assert.equal(only(result, 'fragments').length, 3);
  });

  test('a fragment link to a page missing from a built area fails; one into an unbuilt area is skipped', () => {
    const { edit, run } = fixture();
    edit('dev/install.html', (html) => html.replace('</main>', '<a href="/fieldset/dev/gone#x">x</a>'
      + '<a href="/fieldset/2.1/install#x">x</a><a href="/fieldset/latest/#x">x</a></main>'));
    const result = run();
    assertFails(result, 'fragments', 'dev/install.html', /points to \/fieldset\/dev\/gone, which is not in the output/);
    assertFails(result, 'fragments', 'dev/install.html',
      /"\/fieldset\/latest\/#x": latest\/index\.html has no element/);
    assert.equal(result.counts.fragmentsSkipped, 4);
  });

  test('a fragment that is not valid percent-encoding fails', () => {
    const { edit, run } = fixture();
    edit('dev/install.html', (html) => html.replace('</main>', '<a href="#%E0%A4%A">x</a></main>'));
    assertFails(run(), 'fragments', 'dev/install.html', /"#%E0%A4%A" is not valid percent-encoding/);
  });
});

describe('literals', () => {
  // The placeholders' pages as each source form renders them: raw in prose (v2.1.0-rc.1) and in code spans (U8).
  const LITERAL_PAGES = {
    plain: {
      'dev/field-app-guide.html': '<p>Choose "Weiter mit &lt;organization&gt;" ("Continue with &lt;organization&gt;")</p>',
      'dev/troubleshooting.html': '<p><strong>"Single Sign-on erfordert Ihre Aufmerksamkeit: &lt;provider&gt;"</strong></p>',
      'dev/archive/1.0-troubleshooting.html': '<p>"Single Sign-on erfordert Ihre Aufmerksamkeit: &lt;provider&gt;"</p>',
      'dev/field-app-api-v1.html': '<li>the client offers "Continue with &lt;organization&gt;" or</li>',
    },
    'code span': {
      'dev/field-app-guide.html': '<p>Choose "Weiter mit <code>&lt;organization&gt;</code>" ("Continue with '
        + '<code>&lt;organization&gt;</code>")</p>',
      'dev/troubleshooting.html': '<p><strong>"Single Sign-on erfordert Ihre Aufmerksamkeit: '
        + '<code>&lt;provider&gt;</code>"</strong></p>',
      'dev/archive/1.0-troubleshooting.html': '<p>"Single Sign-on erfordert Ihre Aufmerksamkeit: '
        + '<code>&lt;provider&gt;</code>"</p>',
      'dev/field-app-api-v1.html': '<li>the client offers "Continue with <code>&lt;organization&gt;</code>" or</li>',
    },
  };
  for (const [form, pages] of Object.entries(LITERAL_PAGES)) {
    test(`every placeholder passes ${form === 'plain' ? 'as plain text' : 'inside a code span'}`, () => {
      const { edit, run } = fixture();
      for (const [file, body] of Object.entries(pages)) edit(file, () => page(body));
      const result = run();
      assert.deepEqual(only(result, 'literals'), []);
      assert.equal(result.counts.literals, DEFAULT_LITERALS.length);
    });
  }

  test('a placeholder rendered as an element fails', () => {
    const { edit, run } = fixture();
    edit('dev/field-app-guide.html', (html) => html.replace('<code>&lt;organization&gt;</code>',
      '<organization></organization>'));
    assertFails(run(), 'literals', 'dev/field-app-guide.html', /^does not show "Weiter mit <organization>" as text$/);
  });

  test('an interpolated {{org.name}} and a missing page fail', () => {
    const { edit, path, run } = fixture();
    edit('dev/admin-guide.html', (html) => html.replace('{{org.name}}', 'Example Ltd'));
    rmSync(path('dev/archive/1.0-troubleshooting.html'));
    const result = run();
    assertFails(result, 'literals', 'dev/admin-guide.html', /"\{\{org\.name\}\}"/);
    assertFails(result, 'literals', 'dev/archive/1.0-troubleshooting.html', /^is missing; it must show "Single/);
  });

  test('callers add literals, also for root pages', () => {
    const literals = [...DEFAULT_LITERALS, { build: 'root', page: `${SPEC}/`, text: 'Fieldset Spec 1.0' },
      { page: 'install', text: 'Größe' }, { build: 'root', page: 'latest/', text: 'Not there' }];
    const result = fixture({ literals }).run();
    assert.equal(result.counts.literals, 9);
    assertFails(result, 'literals', 'latest/index.html', /^does not show "Not there" as text$/);
    assert.equal(only(result, 'literals').length, 1);
  });
});

describe('spec files', () => {
  test('a JSON file that differs from its source by one byte fails', () => {
    const { edit, run } = fixture();
    edit(`${SPEC}/meta-schema.json`, (json) => `${json}\n`);
    assertFails(run(), 'spec', `${SPEC}/meta-schema.json`,
      /^differs from .*meta-schema\.json \(sha256 [0-9a-f]{64}, source/);
  });

  test('a JSON file missing from the output or without a source fails', () => {
    const { path, run } = fixture();
    rmSync(path(`${SPEC}/roles.json`));
    writeFileSync(path(`${SPEC}/extra.json`), '{}');
    const result = run();
    assertFails(result, 'spec', `${SPEC}/roles.json`, /^is missing; the source has /);
    assertFails(result, 'spec', `${SPEC}/extra.json`, /^has no source file /);
  });
});

describe('the reference page', () => {
  test('fails without the CSP, or with an altered or misplaced one', () => {
    const cases = {
      removed: (html) => html.replace(`${CSP_META}\n`, ''),
      altered: (html) => html.replace("connect-src 'none'", "connect-src 'self'"),
      'not the literal': (html) => html.replace(CSP_META, CSP_META.replace("'none'", '&#39;none&#39;')),
      late: (html) => html.replace(`${CSP_META}\n`, '').replace('</head>', `${CSP_META}\n</head>`)
        .replace('<head>', '<head>\n<link rel="stylesheet" href="x.css">'),
    };
    const expected = {
      removed: /^has 0 Content-Security-Policy meta elements, expected 1$/,
      altered: /^does not carry the exact CSP literal /,
      'not the literal': /^does not carry the exact CSP literal /,
      late: /^has a <link> before its Content-Security-Policy$/,
    };
    for (const [name, change] of Object.entries(cases)) {
      const { edit, run } = fixture();
      edit('dev/api-reference.html', change);
      assertFails(run(), 'reference', 'dev/api-reference.html', expected[name]);
    }
    assert.ok(CSP_META.includes(REFERENCE_CSP));
  });

  test('fails on an inline script, an inline module, an event handler or a javascript: URL', () => {
    const { edit, run } = fixture();
    edit('dev/api-reference.html', (html) => html.replace('</body>', '<script>alert(1)</script>\n'
      + '<script type="module">import("x")</script>\n<img src="x.png" onerror="alert(1)">\n'
      + '<a href=" javascript:alert(1)">x</a>\n</body>'));
    const failures = only(run(), 'reference').map((failure) => failure.detail);
    assert.deepEqual(failures, ['runs an inline script (type none)', 'runs an inline script (type module)',
      'has an event handler attribute onerror on <img>', 'has a javascript: URL in href on <a>']);
  });

  test('fails when another script is loaded or the data block is missing', () => {
    const { edit, run } = fixture();
    edit('dev/api-reference.html', (html) => html.replace('type="application/json"', 'type="text/plain"')
      .replace('</body>', '<script src="https://cdn.example/x.js"></script>\n</body>'));
    const failures = only(run(), 'reference').map((failure) => failure.detail);
    assert.deepEqual(failures, ['runs an inline script (type text/plain)',
      'loads the script "https://cdn.example/x.js"',
      'has 2 scripts with src, expected 1 (scalar-api-reference.js)', 'has 0 JSON data blocks, expected 1']);
  });

  test('fails when the bundle does not match its integrity attribute', () => {
    const { path, run } = fixture();
    writeFileSync(path('dev/scalar-api-reference.js'), `${readFileSync(path('dev/scalar-api-reference.js'))};alert(1)`);
    const result = run();
    assertFails(result, 'reference', 'dev/api-reference.html', /^integrity "sha256-.*" is not the sha256 of dev\//);
    assertFails(result, 'reference', 'dev/scalar-api-reference.js',
      /is not the installed @scalar\/api-reference bundle/);
  });

  test('fails when bundle and integrity agree but are not the installed package file', () => {
    const { edit, path, run } = fixture();
    const integrity = `sha256-${createHash('sha256').update('alert(1)').digest('base64')}`;
    writeFileSync(path('dev/scalar-api-reference.js'), 'alert(1)');
    edit('dev/api-reference.html', (html) => html.replace(/integrity="[^"]*"/, `integrity="${integrity}"`));
    const result = run();
    assert.deepEqual(only(result, 'reference').map((failure) => failure.file), ['dev/scalar-api-reference.js']);
    assert.match(only(result, 'reference')[0].detail, /is not the installed @scalar\/api-reference bundle/);
    assert.ok(only(result, 'reference')[0].detail.startsWith(`${integrity} `));
  });

  test('fails when the page or the bundle is missing', () => {
    const missingBundle = fixture();
    rmSync(missingBundle.path('dev/scalar-api-reference.js'));
    assertFails(missingBundle.run(), 'reference', 'dev/scalar-api-reference.js', /^is missing; dev\/api-reference/);
    const missingPage = fixture();
    rmSync(missingPage.path('dev/api-reference.html'));
    assertFails(missingPage.run(), 'reference', 'dev/api-reference.html', /^is missing$/);
  });

  test('is skipped in check mode only', () => {
    const { edit, options } = fixture({ mode: 'check' });
    edit('dev/api-reference.html', (html) => html.replace(`${CSP_META}\n`, ''));
    const result = runChecks(options);
    assert.deepEqual(result.failures, []);
    assert.deepEqual(result.skipped, ['reference']);
    assert.equal(only(runChecks({ ...options, mode: 'publish' }), 'reference').length, 1);
  });
});

describe('output files', () => {
  test('a symlinked file or directory fails and is not followed', () => {
    const { path, run } = fixture();
    symlinkSync('/etc/hosts', path('dev/hosts.html'));
    const outside = writeTree(tempDir(), { 'page.html': page('<a href="#broken">x</a>') });
    symlinkSync(outside, path('dev/linked'));
    const result = run();
    assertFails(result, 'files', 'dev/hosts.html', /^is a symbolic link; the output may hold regular files only$/);
    assertFails(result, 'files', 'dev/linked', /^is a symbolic link/);
    assert.deepEqual(only(result, 'fragments'), []);
  });

  test('a FIFO fails', { skip: spawnSync('mkfifo', ['--help']).error && 'mkfifo is not available' }, () => {
    const { path, run } = fixture();
    spawnSync('mkfifo', [path('dev/pipe')]);
    assertFails(run(), 'files', 'dev/pipe', /^is a FIFO/);
  });
});

describe('screenshots and the 404 fallback', () => {
  test('a screenshot the home page shows but the output lacks fails', () => {
    const { path, run } = fixture();
    rmSync(path('screenshots/forms-list.png'));
    assertFails(run(), 'screenshots', 'index.html',
      /^shows "\/fieldset\/screenshots\/forms-list\.png", but screenshots\/forms-list\.png is not in the output$/);
  });

  test('a screenshot the home page does not show fails', () => {
    const { path, run } = fixture();
    writeFileSync(path('screenshots/builder.png'), 'png');
    assertFails(run(), 'screenshots', 'screenshots/builder.png', /^is not shown on the home page$/);
  });

  test('a 404 page without the static fallback, or with it inside #app, fails', () => {
    const plain = fixture();
    writeFileSync(plain.path('404.html'), page(''));
    assertFails(plain.run(), 'fallback', '404.html', /^lacks the static fallback links/);
    const inside = fixture();
    inside.edit('404.html', (html) => {
      const fallback = /<div class="fieldset-static-fallback">.*?<\/div>/.exec(html)[0];
      return html.replace(fallback, '').replace('<div id="app"></div>', `<div id="app">${fallback}</div>`);
    });
    assertFails(inside.run(), 'fallback', '404.html', /inside #app/);
    const missing = fixture();
    rmSync(missing.path('404.html'));
    assertFails(missing.run(), 'fallback', '404.html', /^is missing$/);
  });
});

describe('options', () => {
  test('are validated before anything is read', () => {
    const { options } = fixture();
    const invalid = [
      [{ mode: 'release' }, /mode must be one of dev, publish, check/],
      [{ copy: '../x' }, /copy must be dev or a minor/],
      [{ copy: null, root: false }, /neither a copy nor the root/],
      [{ specSourceDir: undefined }, /the root needs specSourceDir/],
      [{ specSourceDir: join(options.specSourceDir, 'meta-schema.json') }, /the root needs specSourceDir, a directory/],
      [{ literals: [{ page: 'x', text: '' }] }, /literals must be a list/],
      [{ outDir: join(options.outDir, 'nope') }, /is not a directory/],
    ];
    for (const [change, message] of invalid) assert.throws(() => runChecks({ ...options, ...change }), message);
  });

  test('assertChecks throws listing every failure, and returns the result when all pass', () => {
    const { edit, options } = fixture();
    assert.equal(assertChecks(options).failures.length, 0);
    edit('dev/index.html', (html) => html.replace('href="#guides"', 'href="#nope"'));
    rmSync(join(options.outDir, '404.html'));
    assert.throws(() => assertChecks(options), (error) => error.message === 'Post-build checks failed (2):\n'
      + '[fragments] dev/index.html: link "#nope": dev/index.html has no element with id "nope"\n'
      + '[fallback] 404.html: is missing');
  });
});
