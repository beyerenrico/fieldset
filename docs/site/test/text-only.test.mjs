// Content pages render as text, not Vue (plan U2): raw HTML becomes escaped text inside the v-pre wrapper, so
// placeholders and `{{…}}` survive the Vue compile literally. Server-rendering the compiled page shows what readers
// see.
import { after, before, test } from 'node:test';
import assert from 'node:assert/strict';
import { wrapVPre } from '../lib/stage.mjs';
import { cleanup, render, rendererFor, serverRender, stageFixture, vueCompileErrors } from './support/site.mjs';

let site;
let md;
before(async () => {
  site = stageFixture({ pages: { 'docs/guide/field-app-guide.md': '# Field App\n', 'CHANGELOG.md': '# Changelog\n' } });
  md = await rendererFor(site);
});
after(cleanup);

async function page(markdown, repoPath = 'docs/guide/field-app-guide.md') {
  const { html } = await render(md, site, repoPath, wrapVPre(markdown));
  assert.deepEqual(vueCompileErrors(html, repoPath), []);
  return { html, visible: await serverRender(html) };
}

test('a raw placeholder in prose renders as the literal text', async () => {
  const { html, visible } = await page('Choose "Weiter mit <organization>" ("Continue with <organization>").\n');
  assert.match(html, /^<div v-pre>/);
  assert.match(visible, /Weiter mit &lt;organization&gt;&quot; \(&quot;Continue with &lt;organization&gt;&quot;\)/);
  assert.doesNotMatch(visible, /<organization>/);
});

test('{{…}} in inline code and in prose stays literal', async () => {
  const { visible } = await page('Tokens `{{org.name}}` and `{{page}}`; prose {{ 1 + 1 }}.\n');
  assert.match(visible, /<code>\{\{org\.name\}\}<\/code>/);
  assert.match(visible, /prose \{\{ 1 \+ 1 \}\}\./);
});

test('an HTML block becomes a paragraph of text, and scripts never run', async () => {
  const markdown = '<details>\n<summary>More</summary>\n</details>\n\nx <span onclick="alert(1)">y</span>\n';
  const { html, visible } = await page(markdown);
  assert.match(html, /<p>&lt;details&gt;\n&lt;summary&gt;More&lt;\/summary&gt;\n&lt;\/details&gt;<\/p>/);
  assert.match(visible, /<p>x &lt;span onclick=&quot;alert\(1\)&quot;&gt;y&lt;\/span&gt;<\/p>/);
});

test('HTML comments are dropped, as on GitHub', async () => {
  const { html } = await page('<!-- note for editors -->\n\nText <!-- inline --> here.\n');
  assert.doesNotMatch(html, /note for editors|inline/);
  assert.match(html, /<p>Text  here\.<\/p>/);
});

test('a code span broken across lines before <file> stays one code span', async () => {
  const markdown = '- names `--password-file\n  <file>` when the password came from a file\n';
  const { html } = await page(markdown, 'CHANGELOG.md');
  assert.match(html, /<code>--password-file &lt;file&gt;<\/code> when the password/);
});

test('attribute syntax is not applied: no ids, classes or handlers from {…}', async () => {
  const { html } = await page('## Mail {#custom}\n\nText {onclick=alert(1)}\n');
  assert.match(html, /<h2 id="mail-custom"[^>]*>Mail \{#custom\}/);
  assert.match(html, /<p>Text \{onclick=alert\(1\)\}<\/p>/);
});

test('the heading permalink keeps its markup and the page title comes from the h1', async () => {
  const { html, env } = await render(md, site, 'docs/guide/field-app-guide.md', wrapVPre('# Field App <b>guide</b>\n'));
  assert.match(html, /<h1 id="field-app-guide"[^>]*>Field App &lt;b&gt;guide&lt;\/b&gt; <a class="header-anchor"/);
  assert.match(html, /href="#field-app-guide"[^>]*>&#8203;<\/a><\/h1>/);
  assert.equal(env.title, 'Field App <b>guide</b>');
});

// Also the control for the tests above: without the wrapper and the rule, Vue would evaluate the page.
test('a page outside the staged set keeps its HTML and Vue syntax', async () => {
  const markdown = '<span class="hero">Fieldset</span> {{ 1 + 1 }}\n';
  const { html } = await render(md, site, 'docs/site/root/index.md', markdown);
  assert.match(html, /<span class="hero">Fieldset<\/span>/);
  assert.match(await serverRender(html), /<span class="hero">Fieldset<\/span> 2/);
});
