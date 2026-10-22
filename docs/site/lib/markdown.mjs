// The VitePress `markdown` options of a build with content pages (plan U2): GitHub anchors, links resolved at parse
// time and raw HTML as text. VitePress syntax that would change what GitHub shows is off for the whole build: attrs
// (`{#id}`, `{.class}`, event attributes), the component HTML-block rule (a line starting with `<file>` would cut a
// code span in two), `@include` and `<<<` imports (staging rejects both; this keeps them inert).
import { linksPlugin } from './links.mjs';
import { slugify } from './slug.mjs';
import { textOnlyPlugin } from './text-only.mjs';

export function contentMarkdown(site) {
  return {
    anchor: { slugify },
    attrs: false,
    component: false,
    include: false,
    snippet: false,
    config(md) {
      linksPlugin(md, site);
      textOnlyPlugin(md, site);
    },
  };
}
