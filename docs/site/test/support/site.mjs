// Helpers for the content tests (plan U2): stage a fixture repo, then render its pages through VitePress's own markdown
// renderer with the env VitePress's markdownToVue passes (rewritten file as path, staged source as realPath), so the
// tests see final hrefs and targets. The Vue helpers compile and server-render a page the way VitePress wraps it.
import { mkdirSync, mkdtempSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { dirname, join } from 'node:path';
import { createMarkdownRenderer, disposeMdItInstance } from 'vitepress';
import { createSSRApp } from 'vue';
import { compileTemplate, parse } from 'vue/compiler-sfc';
import { renderToString } from 'vue/server-renderer';
import { contentMarkdown } from '../../lib/markdown.mjs';
import { readSite, stageContent } from '../../lib/stage.mjs';

export const RC = Object.freeze({
  minor: '2.1', version: '2.1.0-rc.1', tag: 'v2.1.0-rc.1', prerelease: true, repository: 'beyerenrico/fieldset',
});

const temporary = [];
export const cleanup = () => temporary.splice(0).forEach((dir) => rmSync(dir, { recursive: true, force: true }));

export function tempDir(prefix = 'fieldset-site-') {
  const dir = mkdtempSync(join(tmpdir(), prefix));
  temporary.push(dir);
  return dir;
}

export function writeTree(root, files) {
  for (const [path, content] of Object.entries(files)) {
    mkdirSync(dirname(join(root, path)), { recursive: true });
    writeFileSync(join(root, path), content);
  }
  return root;
}

// pages are written to the source; otherFiles only exist in the repo file index, as files a link may point to.
export function stageFixture({ pages, otherFiles = [], descriptor = RC }) {
  const source = writeTree(tempDir(), pages);
  const out = join(tempDir(), 'stage');
  stageContent({ source, out, descriptor, files: [...Object.keys(pages), ...otherFiles] });
  return readSite(out);
}

// createMarkdownRenderer keeps one instance per process; a new site needs a fresh one.
export async function rendererFor(site) {
  disposeMdItInstance();
  return createMarkdownRenderer(site.srcDir, contentMarkdown(site), site.base);
}

export async function render(md, site, repoPath, src = readFileSync(join(site.srcDir, repoPath), 'utf8')) {
  const sitePath = site.pagePath(repoPath) ?? repoPath;
  const env = {
    path: join(site.srcDir, sitePath), realPath: join(site.srcDir, repoPath), relativePath: sitePath,
    cleanUrls: true, relativizeUrls: true,
  };
  const html = await md.renderAsync(src, env);
  return { html, env };
}

const attr = (tag, name) => new RegExp(`\\s${name}="([^"]*)"`).exec(tag)?.[1];
export function anchors(html) {
  return [...html.matchAll(/(<a\s[^>]*>)(.*?)<\/a>/g)]
    .filter(([, tag]) => !tag.includes('header-anchor'))
    .map(([, tag, text]) => ({ href: attr(tag, 'href'), target: attr(tag, 'target'), text }));
}
export const anchor = (html, text) => anchors(html).find((link) => link.text === text);

export function vueCompileErrors(html, filename) {
  const { descriptor, errors } = parse(`<template><div>${html}</div></template>`, { filename });
  if (errors.length) return errors;
  return compileTemplate({ source: descriptor.template.content, filename, id: 'page' }).errors;
}

export const serverRender = (html) => renderToString(createSSRApp({ template: `<div>${html}</div>` }));
