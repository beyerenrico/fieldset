// Content pages render as text, not Vue (plan U2, KTD "Content pages render as text, not Vue"): raw HTML in a staged
// page becomes escaped text, so `Weiter mit <organization>` shows the placeholder instead of an unknown element that
// breaks the Vue compile. HTML comments are dropped, as GitHub hides them. The rule runs after markdown-it-anchor, so
// heading slugs leave raw HTML out as GitHub does, and it keeps the anchor's own permalink symbol.
import { contentPage } from './stage.mjs';

const COMMENTS_ONLY = /^\s*(<!--[\s\S]*?-->\s*)+$/;

function textToken(state, content, from) {
  const token = new state.Token('text', '', 0);
  token.content = content;
  token.level = from.level;
  return token;
}

function inlineAsText(state, token) {
  if (token.type !== 'html_inline' || token.meta?.isPermalinkSymbol) return [token];
  return COMMENTS_ONLY.test(token.content) ? [] : [textToken(state, token.content, token)];
}

// An HTML block becomes a paragraph holding its source as text.
function blockAsText(state, token) {
  if (token.type !== 'html_block') return [token];
  if (COMMENTS_ONLY.test(token.content)) return [];
  const open = Object.assign(new state.Token('paragraph_open', 'p', 1), { map: token.map, level: token.level });
  const inline = Object.assign(new state.Token('inline', '', 0), { map: token.map, level: token.level + 1 });
  inline.content = token.content.replace(/\n$/, '');
  inline.children = [textToken(state, inline.content, inline)];
  const close = Object.assign(new state.Token('paragraph_close', 'p', -1), { level: token.level });
  return [open, inline, close];
}

export function textOnlyPlugin(md, site) {
  md.core.ruler.push('fieldset_text_only', (state) => {
    if (contentPage(site, state.env) === null) return;
    state.tokens = state.tokens.flatMap((token) => blockAsText(state, token));
    for (const block of state.tokens.filter((token) => token.type === 'inline' && token.children)) {
      block.children = block.children.flatMap((token) => inlineAsText(state, token));
    }
  });
}
