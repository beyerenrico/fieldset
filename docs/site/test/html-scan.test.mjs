// The HTML tokenizer of the post-build checks (plan U12): tags and attributes as a browser reads them, no tags inside
// comments or the raw text of <script> and <style>, decoded character references, and element ranges.
import { describe, test } from 'node:test';
import assert from 'node:assert/strict';
import { decodeEntities, elementIds, elementRange, openTags, scanHtml, visibleText } from '../lib/html-scan.mjs';

const hrefs = (html) => openTags(scanHtml(html), 'a').map((tag) => tag.attrs.href);

describe('scanHtml', () => {
  test('reads tag names and attributes in any case, quoted, unquoted and bare', () => {
    const [tag] = scanHtml('<A HREF="/x#y" Target=_self data-x=\'a>b\' hidden>text</A>');
    assert.equal(tag.name, 'a');
    assert.deepEqual(tag.attrs, { href: '/x#y', target: '_self', 'data-x': 'a>b', hidden: '' });
    assert.equal(tag.closing, false);
  });

  test('keeps the first of two equal attributes, as browsers do', () => {
    assert.deepEqual(hrefs('<a href="#first" href="#second">x</a>'), ['#first']);
  });

  test('decodes character references in attribute values and text', () => {
    const tokens = scanHtml('<p title="&quot;a&quot; &amp; &#39;b&#39;">&lt;organization&gt; &#x7B;&#123; &copy;</p>');
    assert.equal(tokens[0].attrs.title, '"a" & \'b\'');
    assert.equal(tokens[1].text, '<organization> {{ &copy;');
  });

  test('finds no tag inside comments, the doctype or the raw text of script and style', () => {
    const html = '<!DOCTYPE html><!-- <a href="#c"> --><script>let a = \'<a href="#s">\';</script>'
      + '<style>a::before{content:"<a href=#t>"}</style><a href="#real">x</a>';
    assert.deepEqual(hrefs(html), ['#real']);
    const [script, style] = openTags(scanHtml(html)).filter((tag) => tag.body !== undefined);
    assert.equal(script.body, 'let a = \'<a href="#s">\';');
    assert.equal(style.name, 'style');
  });

  test('ends a script at its end tag in any case, and treats a lone < as text', () => {
    const tokens = scanHtml('<script type="application/json">{"a":"\\u003c"}</SCRIPT >1 < 2<b>x</b>');
    assert.equal(tokens[0].body, '{"a":"\\u003c"}');
    assert.equal(visibleText(tokens), '1 < 2x');
  });

  test('marks void and self-closed elements', () => {
    const tags = openTags(scanHtml('<img src="a.png"><br/><div/><p>'));
    assert.deepEqual(tags.map((tag) => [tag.name, tag.selfClosing]), [['img', true], ['br', true], ['div', true],
      ['p', false]]);
  });
});

describe('page helpers', () => {
  test('elementIds lists every decoded id', () => {
    assert.deepEqual([...elementIds(scanHtml('<h2 id="größe">x</h2><div id="a&amp;b"></div><p>no id</p>'))],
      ['größe', 'a&b']);
  });

  test('elementRange spans nested elements of the same name up to the end tag', () => {
    const html = '<body><div id="app"><div><div></div></div><img></div><div class="after"></div></body>';
    const tokens = scanHtml(html);
    const app = tokens.findIndex((token) => token.attrs?.id === 'app');
    const { start, end } = elementRange(tokens, app);
    assert.equal(html.slice(start, end), '<div id="app"><div><div></div></div><img></div>');
    assert.deepEqual(elementRange(tokens, tokens.findIndex((token) => token.name === 'img')),
      { start: html.indexOf('<img>'), end: html.indexOf('<img>') + 5 });
  });

  test('visibleText joins text across inline elements and collapses whitespace', () => {
    const tokens = scanHtml('<p>Choose "Weiter mit <code>&lt;organization&gt;</code>"\n   or <em>not</em></p>');
    assert.equal(visibleText(tokens), 'Choose "Weiter mit <organization>" or not');
  });

  test('decodeEntities leaves unknown names and replaces invalid code points', () => {
    assert.equal(decodeEntities('&unknown; &#0; &#x110000; &nbsp;'), '&unknown; \ufffd \ufffd \u00a0');
  });
});
