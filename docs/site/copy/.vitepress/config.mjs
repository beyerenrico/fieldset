// The VitePress config of one version copy (plan U3, R6, R7, R16, R20; KTD "Permanent URL shape"). It builds the
// staged dir that stageContent (lib/stage.mjs) wrote: base /fieldset/<minor>/ (/fieldset/dev/ for a working-tree
// build), the staged repo paths rewritten to the flat copy layout (docs/guide/x.md -> x, docs/api/field-app-v1.md ->
// field-app-api-v1, CHANGELOG.md -> changelog), clean URLs, local search, the sidebar of the guide index, and the
// theme's switcher and notices, which read the descriptor baked into themeConfig.fieldset.
//
// Invocation (U13): `await buildCopy({ stageDir, outDir })`, or from docs/site:
//   FIELDSET_DOCS_STAGE=<staged dir> npx vitepress build copy --outDir <out dir>
// VitePress loads this file itself and calls the default export without arguments, so the staged dir reaches it only
// through the environment; buildCopy sets FIELDSET_DOCS_STAGE for the duration of one build. Static files of the copy
// (U11's api-reference.html) go into <staged dir>/public/, VitePress's public dir for this srcDir.
import { realpathSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { contentMarkdown } from '../../lib/markdown.mjs';
import { copySidebar } from '../../lib/sidebar.mjs';
import { SITE_ROOT, readSite } from '../../lib/stage.mjs';
import { buildStaged, stagedImports, stageDirFromEnv, STAGE_ENV } from '../../lib/staged-build.mjs';

export { STAGE_ENV };
export const COPY_ROOT = fileURLToPath(new URL('..', import.meta.url));
const CONFIG_FILE = fileURLToPath(import.meta.url);

// Shiki has no grammar for these fences; they render as plain text instead of warning on every build.
const LANGUAGE_ALIAS = { caddyfile: 'txt', cron: 'txt' };

// Nav texts are rendered with v-html by the default theme: constants only. api-reference.html is a static page of the
// copy (U11), so target _self keeps the client router from swallowing the click; base is prefixed as for any page.
const NAV = Object.freeze([
  { text: 'Guides', link: '/', activeMatch: '^/(?!field-app-api-v1$|changelog$)' },
  { text: 'Field App API', link: '/field-app-api-v1' },
  { text: 'API reference', link: '/api-reference.html', target: '_self' },
  { text: 'Changelog', link: '/changelog' },
]);

const copyOf = ({ minor, version, tag, prerelease, repository, ref }) => ({
  minor, version, tag, prerelease, repository, ref,
});

function themeConfig(site) {
  return {
    siteTitle: 'Fieldset',
    // The home page is another build: a full path and target _self (VitePress would prefix base and route it).
    logoLink: { link: SITE_ROOT, target: '_self' },
    nav: NAV,
    sidebar: copySidebar(site),
    outline: { level: [2, 3] },
    search: { provider: 'local' },
    // R14: the page's source at the copy's tag (the commit for a working-tree build); :path is the staged repo path.
    editLink: { pattern: `https://github.com/${site.repository}/blob/${site.ref}/:path`, text: 'View this page on GitHub' },
    fieldset: { copy: copyOf(site) },
  };
}

// The config for the staged dir. Its real path is used throughout: Vite resolves module ids through symlinks (macOS
// tmp dirs), and the links rule and VitePress's rewrites both match page files against srcDir.
export function copyConfig({ stageDir }) {
  const site = readSite(realpathSync(stageDir));
  const label = site.minor === 'dev' ? 'development build' : site.minor;
  return {
    srcDir: site.srcDir,
    base: site.base,
    rewrites: (page) => site.pagePath(page),
    cleanUrls: true,
    lastUpdated: false,
    lang: 'en-US',
    title: 'Fieldset',
    titleTemplate: `:title | Fieldset ${label}`,
    description: `Fieldset documentation, ${label}: install, operate and integrate the self-hosted forms platform.`,
    markdown: { ...contentMarkdown(site), languageAlias: LANGUAGE_ALIAS },
    themeConfig: themeConfig(site),
    vite: { plugins: [stagedImports(site.srcDir, CONFIG_FILE)] },
  };
}

export const buildCopy = ({ stageDir, outDir }) => buildStaged({ root: COPY_ROOT, stageDir, outDir });

export default function config() {
  return copyConfig({ stageDir: stageDirFromEnv() });
}
