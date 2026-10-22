// A small HTML tokenizer for the post-build checks (plan U12): tags with their attributes and offsets, text, and the
// raw body of <script> and <style>. It needs no dependency because it reads only what VitePress and lib/openapi.mjs
// write, which is regular: quoted attributes, no unclosed tags. It still follows the HTML rules the checks rely on:
// comments, the doctype and the raw text of <script> and <style> hold no tags; attribute and tag names ignore case;
// the first of two equal attributes wins; character references in text and attribute values are decoded.

const VOID = new Set(['area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'source', 'track',
  'wbr']);
const RAW_TEXT = new Set(['script', 'style']);
const TAG_START = /<(\/?)([a-zA-Z][a-zA-Z0-9:-]*)/y;
const ATTRIBUTE = /\s*([^\s"'<>/=]+)(?:\s*=\s*(?:"([^"]*)"|'([^']*)'|([^\s"'=<>`]+)))?/y;
const TAG_END = /\s*(\/?)>/y;
const NAMED = Object.freeze({ amp: '&', lt: '<', gt: '>', quot: '"', apos: "'", nbsp: ' ' });
const REFERENCE = /&(?:#(\d+)|#[xX]([0-9a-fA-F]+)|([a-zA-Z]+));/g;

function codePoint(value) {
  return value > 0 && value <= 0x10ffff ? String.fromCodePoint(value) : '�';
}

// Numeric references and the named ones VitePress, Vue and markdown-it write; any other name stays as written.
export function decodeEntities(text) {
  return text.replace(REFERENCE, (whole, decimal, hex, name) => {
    if (decimal) return codePoint(Number.parseInt(decimal, 10));
    if (hex) return codePoint(Number.parseInt(hex, 16));
    return Object.hasOwn(NAMED, name) ? NAMED[name] : whole;
  });
}

// Reads a tag's attributes from `at` to its `>`; null when the tag never ends.
function readAttributes(html, at) {
  const attrs = {};
  let position = at;
  while (position < html.length) {
    TAG_END.lastIndex = position;
    const end = TAG_END.exec(html);
    if (end) return { attrs, selfClosing: end[1] === '/', end: TAG_END.lastIndex };
    ATTRIBUTE.lastIndex = position;
    const attribute = ATTRIBUTE.exec(html);
    if (!attribute) { position += 1; continue; }
    const name = attribute[1].toLowerCase();
    const value = attribute[2] ?? attribute[3] ?? attribute[4] ?? '';
    if (!Object.hasOwn(attrs, name)) attrs[name] = decodeEntities(value);
    position = ATTRIBUTE.lastIndex;
  }
  return null;
}

// The end of a raw text element's body: the start of its end tag, or the end of the input.
function rawTextEnd(html, name, from) {
  const end = new RegExp(`</${name}[\\s/>]`, 'ig');
  end.lastIndex = from;
  return end.exec(html)?.index ?? html.length;
}

// One tag at `start` (html[start] is '<'), or null when `<` starts no tag and is text.
function readTag(html, start) {
  TAG_START.lastIndex = start;
  const match = TAG_START.exec(html);
  if (!match) return null;
  const rest = readAttributes(html, TAG_START.lastIndex);
  if (!rest) return null;
  const name = match[2].toLowerCase();
  const tag = { type: 'tag', name, closing: match[1] === '/', attrs: rest.attrs, start, end: rest.end };
  tag.selfClosing = rest.selfClosing || VOID.has(name);
  if (!tag.closing && RAW_TEXT.has(name)) {
    const bodyEnd = rawTextEnd(html, name, rest.end);
    tag.body = html.slice(rest.end, bodyEnd);
    tag.bodyEnd = bodyEnd;
  }
  return tag;
}

// Skips a comment, a doctype or another `<!`/`<?` construct; returns the offset after it, or -1 if `<` is text.
function skipMarkup(html, at) {
  if (html.startsWith('<!--', at)) {
    const end = html.indexOf('-->', at + 4);
    return end === -1 ? html.length : end + 3;
  }
  if (html[at + 1] !== '!' && html[at + 1] !== '?') return -1;
  const end = html.indexOf('>', at);
  return end === -1 ? html.length : end + 1;
}

// [{ type: 'tag', name, closing, selfClosing, attrs, start, end, body?, bodyEnd? } |
//  { type: 'text', text, start, end }]: text tokens carry decoded text; a raw text element's body is the `body` of its
// opening tag, not a text token. A `/>` closes any element, as in SVG.
export function scanHtml(html) {
  const tokens = [];
  let textStart = 0;
  let at = 0;
  const flushText = (end) => {
    if (end <= textStart) return;
    tokens.push({ type: 'text', text: decodeEntities(html.slice(textStart, end)), start: textStart, end });
  };
  while ((at = html.indexOf('<', at)) !== -1) {
    const skipped = skipMarkup(html, at);
    const tag = skipped === -1 ? readTag(html, at) : null;
    if (skipped === -1 && !tag) { at += 1; continue; }
    flushText(at);
    if (tag) tokens.push(tag);
    at = skipped === -1 ? (tag.bodyEnd ?? tag.end) : skipped;
    textStart = at;
  }
  flushText(html.length);
  return tokens;
}

export const openTags = (tokens, name) => tokens.filter((token) => token.type === 'tag' && !token.closing
  && (name === undefined || token.name === name));

// The offsets [start, end) of the element whose opening tag is tokens[index], up to the end of its end tag. Counts
// nested elements of the same name, which is exact for the well-formed markup the builds write.
export function elementRange(tokens, index) {
  const open = tokens[index];
  if (open.selfClosing) return { start: open.start, end: open.end };
  let depth = 0;
  for (let at = index; at < tokens.length; at += 1) {
    const token = tokens[at];
    if (token.type !== 'tag' || token.name !== open.name || token.selfClosing) continue;
    depth += token.closing ? -1 : 1;
    if (depth === 0) return { start: open.start, end: token.end };
  }
  return { start: open.start, end: Number.POSITIVE_INFINITY };
}

// The `id` values of a page, decoded.
export const elementIds = (tokens) => new Set(openTags(tokens).map((tag) => tag.attrs.id)
  .filter((id) => id !== undefined));

// The page's text as a reader sees it: text between tags joined, whitespace runs collapsed to one space.
export const visibleText = (tokens) => normalizeSpace(tokens.filter((token) => token.type === 'text')
  .map((token) => token.text).join(''));

export const normalizeSpace = (text) => text.replace(/\s+/g, ' ').trim();
