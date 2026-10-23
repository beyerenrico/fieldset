// GitHub-compatible heading anchors (plan U2, KTD "GitHub-compatible anchors"): github-slugger's stateless slug()
// replaces VitePress's slugify, so `#adopt-a-10-install` works on GitHub and on the site. markdown-it-anchor adds the
// -1, -2 suffixes for repeated headings as GitHub does. Never use the stateful Slugger class here: VitePress runs link
// fragments through the same function, and a counter would rewrite them.
import { slug } from 'github-slugger';

export const slugify = (text) => slug(text);
