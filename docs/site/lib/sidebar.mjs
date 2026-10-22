// The version copy's sidebar (plan U3: "The sidebar follows the guide index's sections"). It is parsed from the staged
// docs/guide/index.md rather than hard-coded, because tooling from main builds every tag's content: each `## ` section
// with table rows becomes a group, the first link of a row an item, and a row whose other cells link further pages
// (the archive) a collapsed group of them. Links resolve through the U2 rule; only pages of this copy are kept, since
// VitePress prefixes `base` to every sidebar link (the spec, ADRs, README and policy stay on the index page). Pages the
// index does not reach are listed last, so every page stays reachable. VitePress renders sidebar texts with v-html, so
// every text is escaped here.
import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { resolveLink } from './links.mjs';
import { wrapperLines } from './stage.mjs';

const INDEX = 'docs/guide/index.md';
const LINK = /\[((?:[^\]\\]|\\.)+)\]\(([^)\s]+)\)/g;
const HTML_ESCAPES = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' };

export const escapeHtml = (text) => text.replace(/[&<>"']/g, (char) => HTML_ESCAPES[char]);
const plainText = (markdown) => escapeHtml(markdown.replace(/`/g, '').replace(/\\(.)/g, '$1').trim());
const capitalized = (text) => text.charAt(0).toUpperCase() + text.slice(1);

// '/install.md#flags' -> '/install', '/index.md' -> '/', '/archive/index.md' -> '/archive/'.
const sidebarLink = (sitePath) => sitePath.replace(/[?#].*$/, '').replace(/\.md$/, '').replace(/(^|\/)index$/, '$1');

// The page links of one index line, in order: { text, link }; links that leave the copy are dropped.
function pageLinks(line, lineNumber, site) {
  return [...line.matchAll(LINK)].flatMap(([, text, href]) => {
    const resolved = resolveLink(href, { repoPath: INDEX, line: lineNumber }, site);
    if (!resolved || resolved.target || !resolved.href.startsWith('/')) return [];
    return [{ text: plainText(text), link: sidebarLink(resolved.href) }];
  });
}

// A group's header keeps the row's link only when no item repeats it (the archive row links its first page twice).
function rowItem([first, ...more]) {
  if (more.length === 0) return first;
  const items = more.map((item) => ({ ...item, text: capitalized(item.text) }));
  const header = items.some((item) => item.link === first.link) ? { text: first.text } : first;
  return { ...header, collapsed: true, items };
}

// [{ text, items }] for the index's `## ` sections, skipping sections without page links (the terms table).
function indexSections(source, site) {
  const offset = wrapperLines(source);
  const sections = [];
  source.split('\n').forEach((line, index) => {
    const heading = /^## (.+)$/.exec(line);
    if (heading) sections.push({ text: plainText(heading[1]), items: [] });
    else if (sections.length && line.startsWith('|')) {
      const row = pageLinks(line, index + 1 - offset, site);
      if (row.length) sections.at(-1).items.push(rowItem(row));
    }
  });
  return sections.filter((section) => section.items.length);
}

function pageTitle(site, repoPath) {
  const heading = /^# (.+)$/m.exec(readFileSync(join(site.srcDir, repoPath), 'utf8'));
  return plainText(heading ? heading[1] : repoPath);
}

const linksOf = (items) => items.flatMap((item) => [item.link, ...linksOf(item.items ?? [])]);

function unreachedPages(site, reached) {
  return [...site.files]
    .filter((path) => site.pagePath(path) !== null)
    .sort()
    .map((path) => ({ text: pageTitle(site, path), link: sidebarLink(`/${site.pagePath(path)}`) }))
    .filter((item) => !reached.has(item.link));
}

// The themeConfig.sidebar of a copy built from the staged dir that readSite(dir) describes.
export function copySidebar(site) {
  const hasIndex = site.files.has(INDEX) && site.pagePath(INDEX) !== null;
  const source = hasIndex ? readFileSync(join(site.srcDir, INDEX), 'utf8') : '';
  const sidebar = hasIndex ? [{ text: 'Overview', link: '/' }, ...indexSections(source, site)] : [];
  const more = unreachedPages(site, new Set(linksOf(sidebar)));
  return more.length ? [...sidebar, { text: 'More pages', collapsed: false, items: more }] : sidebar;
}
