// The VitePress config of the unversioned root (plan U4, R4, R5, R8, R11; KTD "Permanent URL shape"). It builds the
// dir that stageRoot (lib/root-stage.mjs) wrote: base /fieldset/, the home page, the spec of form-definition 1.0 at
// /fieldset/spec/form-definition/1.0/ (README and changelog as text pages, its JSON files raw beside them),
// /fieldset/latest/ and the 404 page, both of which send visitors on (lib/root-redirect.mjs).
//
// Invocation (U13): `stageRoot({ source, out, descriptor, versions })`, then `await buildRoot({ stageDir, outDir })`,
// or from docs/site: FIELDSET_DOCS_STAGE=<staged dir> npx vitepress build root --outDir <out dir>
// The versions.json the pages bake in is part of the staged dir (root.json), so building needs no other input.
// Output (root-owned unless noted): index.html, 404.html, hashmap.json, assets/, latest/index.html, screenshots/
// (when the home page has any), and spec/form-definition/1.0/ (index.html, changelog.html and the JSON files).
import { realpathSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { contentMarkdown } from '../../lib/markdown.mjs';
import { withStaticFallback } from '../../lib/root-fallback.mjs';
import { homeModel } from '../../lib/root-home.mjs';
import { readRootSite } from '../../lib/root-stage.mjs';
import { buildStaged, stagedImports, stageDirFromEnv } from '../../lib/staged-build.mjs';
import { SITE_ROOT } from '../../lib/stage.mjs';

export const ROOT_DIR = fileURLToPath(new URL('..', import.meta.url));
const CONFIG_FILE = fileURLToPath(import.meta.url);
const SPEC = '/spec/form-definition/1.0/';

// Nav and sidebar links go through VitePress's withBase, so pages of other builds are given relative to /fieldset/ and
// carry target _self, which keeps the client router from swallowing the click. Their texts are rendered with v-html:
// constants only.
const relativeToBase = (url) => url.slice(SITE_ROOT.length - 1);

function nav(urls) {
  return [
    { text: 'Guides', link: relativeToBase(urls.guides), target: '_self' },
    { text: 'Spec', link: SPEC, activeMatch: '^/spec/' },
    { text: 'Changelog', link: relativeToBase(urls.changelog), target: '_self' },
  ];
}

const SIDEBAR = Object.freeze({
  '/spec/': [{
    text: 'Fieldset Spec 1.0',
    items: [{ text: 'Specification', link: SPEC }, { text: 'Changelog', link: `${SPEC}changelog` }],
  }],
});

function themeConfig(site, home) {
  return {
    siteTitle: 'Fieldset',
    nav: nav(home.urls),
    sidebar: SIDEBAR,
    socialLinks: [{ icon: 'github', link: `https://github.com/${site.repository}` }],
    outline: { level: [2, 3] },
    search: { provider: 'local' },
    // R14: the spec page's source at the release's tag (the commit for a working-tree build).
    editLink: { pattern: `https://github.com/${site.repository}/blob/${site.ref}/:path`, text: 'View this page on GitHub' },
    fieldset: { versions: site.versions, home },
  };
}

// The config for the staged dir, under its real path (see copy/.vitepress/config.mjs).
export function rootConfig({ stageDir }) {
  const site = readRootSite(realpathSync(stageDir));
  return {
    srcDir: site.srcDir,
    base: site.base,
    rewrites: (page) => site.pagePath(page),
    cleanUrls: true,
    lastUpdated: false,
    lang: 'en-US',
    title: 'Fieldset',
    titleTemplate: ':title | Fieldset',
    description: 'Fieldset, the free self-hosted platform for digital forms: documentation and the Fieldset Spec.',
    markdown: contentMarkdown(site),
    themeConfig: themeConfig(site, homeModel(site.versions, site.repository)),
    transformHtml: (html, file, { page }) => (page === '404.md' ? withStaticFallback(html) : html),
    vite: { plugins: [stagedImports(site.srcDir, CONFIG_FILE)] },
  };
}

export const buildRoot = ({ stageDir, outDir }) => buildStaged({ root: ROOT_DIR, stageDir, outDir });

export default function config() {
  return rootConfig({ stageDir: stageDirFromEnv() });
}
