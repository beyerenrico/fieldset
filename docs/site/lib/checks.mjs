// Post-build checks (plan U12, R5, R13, R15, R22): they prove a build before it leaves the build job. They read the
// assembled output, the tree served at /fieldset/ ("Assembled site and ownership"): the version copy at
// <outDir>/<copy>/ and, when the root was built, the root's files at <outDir>/ (index.html, 404.html, latest/, spec/,
// screenshots/). Every failure names the check, the output file and what is wrong; nothing is fixed or skipped
// silently.
//
//   files        no symlink or other non-regular file anywhere in the output
//   fragments    every internal link with a #fragment, in every page of the output, resolves to an element id on its
//                target page; links into a build that is not in the output (another copy, the root when it was not
//                built, /fieldset/latest/<path> redirects) and fragments of non-HTML files cannot be checked here
//   literals     listed strings appear in the text of listed pages (raw placeholders and {{…}} shown literally)
//   spec         (root) every JSON file of spec/form-definition/1.0/ equals its source byte for byte, both ways (R5)
//   screenshots  (root) every screenshots/ image the home page shows exists, and every file there is shown
//   fallback     (root) 404.html carries the static fallback links outside #app (lib/root-fallback.mjs)
//   reference    (copy, not in `check` mode) api-reference.html carries the exact CSP literal before any resource,
//                runs no inline script (only the JSON data block and the bundle with src), and the bundle's sha256
//                equals its integrity attribute and the installed package file's (lib/openapi.mjs, R22)
//
// Use (U13): const { failures } = runChecks({ outDir, copy: '2.1', root: true, mode: 'publish', specSourceDir });
// or assertChecks(sameOptions), which throws listing every failure.
import { createHash } from 'node:crypto';
import { lstatSync, readdirSync, readFileSync, statSync } from 'node:fs';
import { join, relative, sep } from 'node:path';
import { elementIds, elementRange, normalizeSpace, openTags, scanHtml, visibleText } from './html-scan.mjs';
import { REFERENCE_CSP, REFERENCE_PAGE, SCALAR_BUNDLE, scalarBundle } from './openapi.mjs';
import { FALLBACK_CLASS, FALLBACK_HTML } from './root-fallback.mjs';
import { SPEC_DIR } from './root-stage.mjs';
import { SITE_ROOT } from './stage.mjs';

export const MODES = Object.freeze(['dev', 'publish', 'check']);
export const SITE_ORIGIN = 'https://beyerenrico.github.io';
export const CSP_META = `<meta http-equiv="Content-Security-Policy" content="${REFERENCE_CSP}">`;
const COPY_NAME = /^(dev|\d+\.\d+)$/;
const SCREENSHOTS = 'screenshots/';

// Strings the copy's pages must show as text (U2's text-only rule; U8 may wrap them in code spans, which keeps the
// text). page: the page's path in its build without .html; build: 'copy' (default) or 'root'.
const LITERAL = (page, text) => Object.freeze({ build: 'copy', page, text });
export const DEFAULT_LITERALS = Object.freeze([
  LITERAL('field-app-guide', 'Weiter mit <organization>'),
  LITERAL('field-app-guide', 'Continue with <organization>'),
  LITERAL('troubleshooting', 'Single Sign-on erfordert Ihre Aufmerksamkeit: <provider>'),
  LITERAL('archive/1.0-troubleshooting', 'Single Sign-on erfordert Ihre Aufmerksamkeit: <provider>'),
  LITERAL('field-app-api-v1', 'Continue with <organization>'),
  LITERAL('admin-guide', '{{org.name}}'),
]);

const failure = (check, file, detail) => ({ check, file, detail });
const sha256 = (bytes) => createHash('sha256').update(bytes).digest();

function irregularKind(stat) {
  if (stat.isSymbolicLink()) return 'a symbolic link';
  if (stat.isFIFO()) return 'a FIFO';
  if (stat.isSocket()) return 'a socket';
  return stat.isBlockDevice() || stat.isCharacterDevice() ? 'a device' : 'not a regular file';
}

// Regular files as output paths ('2.1/install.html'), and a failure for every other entry. Links are never followed.
function walkOutput(outDir) {
  const files = [];
  const irregular = [];
  const visit = (dir) => readdirSync(dir).sort().forEach((name) => {
    const path = join(dir, name);
    const stat = lstatSync(path);
    if (stat.isDirectory()) return visit(path);
    const file = relative(outDir, path).split(sep).join('/');
    if (stat.isFile()) return files.push(file);
    return irregular.push(failure('files', file, `is ${irregularKind(stat)}; the output may hold regular files only`));
  });
  visit(outDir);
  return { files, irregular };
}

const isDirectory = (path) => typeof path === 'string'
  && statSync(path, { throwIfNoEntry: false })?.isDirectory() === true;

function validLiteral(literal) {
  return typeof literal?.page === 'string' && typeof literal.text === 'string' && literal.text.trim() !== ''
    && ['copy', 'root', undefined].includes(literal.build);
}

function validateOptions({ outDir, copy, root, mode, specSourceDir, literals }) {
  if (!MODES.includes(mode)) throw new Error(`Checks: mode must be one of ${MODES.join(', ')}`);
  if (copy !== null && !COPY_NAME.test(copy)) throw new Error('Checks: copy must be dev or a minor such as 2.1');
  if (typeof root !== 'boolean') throw new Error('Checks: root must be true or false');
  if (copy === null && !root) throw new Error('Checks: neither a copy nor the root to check');
  if (root && !isDirectory(specSourceDir)) throw new Error('Checks: the root needs specSourceDir, a directory');
  if (!Array.isArray(literals) || !literals.every(validLiteral)) {
    throw new Error('Checks: literals must be a list of { page, text, build?: copy|root }');
  }
  if (!isDirectory(outDir)) throw new Error(`Checks: ${outDir} is not a directory`);
}

function checkContext(options) {
  const input = {
    ...options, copy: options.copy ?? null, root: options.root ?? false, literals: options.literals ?? DEFAULT_LITERALS,
  };
  validateOptions(input);
  const { files, irregular } = walkOutput(input.outDir);
  const pages = new Map();
  const page = (file) => {
    if (!pages.has(file)) {
      const html = readFileSync(join(input.outDir, file), 'utf8');
      const tokens = scanHtml(html);
      pages.set(file, { html, tokens, ids: elementIds(tokens) });
    }
    return pages.get(file);
  };
  const built = (area) => (area === 'copy' ? input.copy !== null : area === 'root' && input.root);
  return { ...input, files, fileSet: new Set(files), irregular, page, built };
}

// The URL a page is served at, with clean URLs: 2.1/install.html -> /fieldset/2.1/install, 2.1/index.html ->
// /fieldset/2.1/.
const pageUrl = (file) => new URL(`${SITE_ROOT}${file.replace(/(^|\/)index\.html$/, '$1').replace(/\.html$/, '')}`,
  SITE_ORIGIN);

// The build a site path (below /fieldset/) belongs to.
function areaOf(path, copy) {
  const [top, ...rest] = path.split('/');
  if (top === copy) return 'copy';
  if (COPY_NAME.test(top)) return 'other';
  return top === 'latest' && !['', 'index.html'].includes(rest.join('/')) ? 'redirect' : 'root';
}

// The output files a site path is served from, in GitHub Pages' order.
function servedFrom(path) {
  if (path === '' || path.endsWith('/')) return [`${path}index.html`];
  return path.endsWith('.html') ? [path] : [`${path}.html`, `${path}/index.html`, path];
}

// { path, fragment } of an internal link with a fragment, { error } for an unreadable one, null for any other link.
function fragmentTarget(href, base) {
  let url;
  try {
    url = new URL(href, base);
  } catch {
    return { error: 'is not a valid URL' };
  }
  if (url.origin !== SITE_ORIGIN || !url.pathname.startsWith(SITE_ROOT) || url.hash.length <= 1) return null;
  try {
    const path = decodeURIComponent(url.pathname.slice(SITE_ROOT.length));
    return { path, fragment: decodeURIComponent(url.hash.slice(1)) };
  } catch {
    return { error: 'is not valid percent-encoding' };
  }
}

// What checking one link's fragment came to: { result: 'none' } (no internal fragment), 'skipped', 'checked', or
// { result: 'failed', detail }.
function fragmentOutcome(ctx, file, href) {
  const target = fragmentTarget(href, pageUrl(file));
  if (target === null) return { result: 'none' };
  if (target.error) return { result: 'failed', detail: `link "${href}" ${target.error}` };
  const served = servedFrom(target.path).find((candidate) => ctx.fileSet.has(candidate));
  if (!served) {
    if (!ctx.built(areaOf(target.path, ctx.copy))) return { result: 'skipped' };
    const detail = `link "${href}" points to /fieldset/${target.path}, which is not in the output`;
    return { result: 'failed', detail };
  }
  if (!served.endsWith('.html')) return { result: 'skipped' };
  if (ctx.page(served).ids.has(target.fragment)) return { result: 'checked' };
  return { result: 'failed', detail: `link "${href}": ${served} has no element with id "${target.fragment}"` };
}

const LINK_TAGS = new Set(['a', 'area']);

function fragmentCheck(ctx) {
  const outcomes = ctx.files.filter((file) => file.endsWith('.html')).flatMap((file) => openTags(ctx.page(file).tokens)
    .filter((tag) => LINK_TAGS.has(tag.name) && tag.attrs.href !== undefined)
    .map((tag) => ({ file, ...fragmentOutcome(ctx, file, tag.attrs.href) })));
  const count = (result) => outcomes.filter((outcome) => outcome.result === result).length;
  return {
    failures: outcomes.filter(({ result }) => result === 'failed')
      .map(({ file, detail }) => failure('fragments', file, detail)),
    checked: count('checked'),
    skipped: count('skipped'),
  };
}

const literalFile = ({ build = 'copy', page }, copy) => {
  const path = servedFrom(page.replace(/^\/+/, ''))[0];
  return build === 'copy' ? `${copy}/${path}` : path;
};

function literalCheck(ctx) {
  const due = ctx.literals.filter((literal) => ctx.built(literal.build ?? 'copy'));
  const failures = due.flatMap((literal) => {
    const file = literalFile(literal, ctx.copy);
    if (!ctx.fileSet.has(file)) return [failure('literals', file, `is missing; it must show "${literal.text}"`)];
    const { tokens } = ctx.page(file);
    if (visibleText(tokens).includes(normalizeSpace(literal.text))) return [];
    return [failure('literals', file, `does not show "${literal.text}" as text`)];
  });
  return { failures, checked: due.length };
}

const isJsonName = (name) => name.endsWith('.json');

function specCheck(ctx) {
  const prefix = `${SPEC_DIR}/`;
  const built = ctx.files.filter((file) => file.startsWith(prefix) && /^[^/]+\.json$/.test(file.slice(prefix.length)))
    .map((file) => file.slice(prefix.length));
  const source = readdirSync(ctx.specSourceDir).filter(isJsonName)
    .filter((name) => lstatSync(join(ctx.specSourceDir, name)).isFile());
  const names = [...new Set([...built, ...source])].sort();
  const failures = names.flatMap((name) => {
    const file = `${prefix}${name}`;
    const from = join(ctx.specSourceDir, name);
    if (!source.includes(name)) return [failure('spec', file, `has no source file ${from}`)];
    if (!built.includes(name)) return [failure('spec', file, `is missing; the source has ${from}`)];
    const [out, src] = [join(ctx.outDir, file), from].map((path) => readFileSync(path));
    if (out.equals(src)) return [];
    return [failure('spec', file, `differs from ${from} (sha256 ${sha256(out).toString('hex')}, source `
      + `${sha256(src).toString('hex')})`)];
  });
  return { failures, checked: names.length };
}

// Every URL a tag names in src, href, poster or srcset.
function tagUrls(tag) {
  const single = ['src', 'href', 'poster'].map((name) => tag.attrs[name]).filter((url) => url !== undefined);
  const set = (tag.attrs.srcset ?? '').split(',').map((part) => part.trim().split(/\s+/)[0]).filter(Boolean);
  return [...single, ...set];
}

function sitePath(url, base) {
  try {
    const resolved = new URL(url, base);
    if (resolved.origin !== SITE_ORIGIN || !resolved.pathname.startsWith(SITE_ROOT)) return null;
    return decodeURIComponent(resolved.pathname.slice(SITE_ROOT.length));
  } catch {
    return null;
  }
}

function screenshotCheck(ctx) {
  const home = 'index.html';
  if (!ctx.fileSet.has(home)) return { failures: [failure('screenshots', home, 'is missing')], checked: 0 };
  const shown = new Map(openTags(ctx.page(home).tokens).flatMap(tagUrls)
    .map((url) => [sitePath(url, pageUrl(home)), url]).filter(([path]) => path?.startsWith(SCREENSHOTS)));
  const files = ctx.files.filter((file) => file.startsWith(SCREENSHOTS));
  const failures = [
    ...[...shown].filter(([path]) => !ctx.fileSet.has(path))
      .map(([path, url]) => failure('screenshots', home, `shows "${url}", but ${path} is not in the output`)),
    ...files.filter((file) => !shown.has(file))
      .map((file) => failure('screenshots', file, 'is not shown on the home page')),
  ];
  return { failures, checked: new Set([...shown.keys(), ...files]).size };
}

function fallbackProblem(html, tokens) {
  const index = tokens.findIndex((token) => token.type === 'tag' && !token.closing && token.name === 'div'
    && token.attrs.class === FALLBACK_CLASS);
  if (index === -1 || !html.startsWith(FALLBACK_HTML, tokens[index].start)) {
    return 'lacks the static fallback links to /fieldset/latest/ and /fieldset/';
  }
  const at = tokens[index].start;
  const body = openTags(tokens, 'body')[0];
  if (!body || at < body.end) return 'carries the static fallback outside <body>';
  const app = tokens.findIndex((token) => token.type === 'tag' && !token.closing && token.attrs.id === 'app');
  const range = app === -1 ? null : elementRange(tokens, app);
  const inApp = range !== null && at >= range.start && at < range.end;
  return inApp ? 'carries the static fallback inside #app, which the app replaces' : null;
}

function fallbackCheck(ctx) {
  const file = '404.html';
  if (!ctx.fileSet.has(file)) return { failures: [failure('fallback', file, 'is missing')], checked: 1 };
  const { html, tokens } = ctx.page(file);
  const problem = fallbackProblem(html, tokens);
  return { failures: problem ? [failure('fallback', file, problem)] : [], checked: 1 };
}

function cspProblems(html, tokens) {
  const metas = openTags(tokens, 'meta')
    .filter((tag) => tag.attrs['http-equiv']?.trim().toLowerCase() === 'content-security-policy');
  if (metas.length !== 1) return [`has ${metas.length} Content-Security-Policy meta elements, expected 1`];
  const [meta] = metas;
  const problems = [];
  if (meta.attrs.content !== REFERENCE_CSP || !html.includes(CSP_META)) {
    problems.push(`does not carry the exact CSP literal ${CSP_META}`);
  }
  const first = openTags(tokens).find((tag) => ['script', 'style', 'link', 'body'].includes(tag.name));
  if (first && first.start < meta.start) problems.push(`has a <${first.name}> before its Content-Security-Policy`);
  return problems;
}

const isJsonType = (type) => type?.trim().toLowerCase() === 'application/json';
const URL_ATTRIBUTES = ['href', 'src', 'action', 'formaction', 'xlink:href'];

function scriptProblems(tokens) {
  const scripts = openTags(tokens, 'script');
  const bundles = scripts.filter((tag) => tag.attrs.src !== undefined);
  const data = scripts.filter((tag) => tag.attrs.src === undefined && isJsonType(tag.attrs.type));
  const inline = scripts.filter((tag) => tag.attrs.src === undefined && !isJsonType(tag.attrs.type));
  const tags = openTags(tokens);
  return [
    ...inline.map((tag) => `runs an inline script (type ${tag.attrs.type ?? 'none'})`),
    ...bundles.filter((tag) => tag.attrs.src !== SCALAR_BUNDLE).map((tag) => `loads the script "${tag.attrs.src}"`),
    ...bundles.filter((tag) => tag.body.trim() !== '').map(() => 'has a script with both src and a body'),
    ...(bundles.length === 1 ? [] : [`has ${bundles.length} scripts with src, expected 1 (${SCALAR_BUNDLE})`]),
    ...(data.length === 1 ? [] : [`has ${data.length} JSON data blocks, expected 1`]),
    ...tags.flatMap((tag) => Object.keys(tag.attrs).filter((name) => name.startsWith('on'))
      .map((name) => `has an event handler attribute ${name} on <${tag.name}>`)),
    ...tags.flatMap((tag) => URL_ATTRIBUTES.filter((name) => /^\s*javascript:/i.test(tag.attrs[name] ?? ''))
      .map((name) => `has a javascript: URL in ${name} on <${tag.name}>`)),
  ];
}

function installedIntegrity(packageDir) {
  try {
    return { integrity: scalarBundle(packageDir).integrity };
  } catch (error) {
    return { error: error.message };
  }
}

function bundleFailures(ctx, page, tokens) {
  const tag = openTags(tokens, 'script').find((script) => script.attrs.src === SCALAR_BUNDLE);
  const file = `${ctx.copy}/${SCALAR_BUNDLE}`;
  if (!tag) return [];
  if (!ctx.fileSet.has(file)) return [failure('reference', file, `is missing; ${page} loads it`)];
  const actual = `sha256-${sha256(readFileSync(join(ctx.outDir, file))).toString('base64')}`;
  const installed = installedIntegrity(ctx.packageDir);
  return [
    ...(tag.attrs.integrity === actual ? [] : [failure('reference', page,
      `integrity "${tag.attrs.integrity ?? ''}" is not the sha256 of ${file} (${actual})`)]),
    ...(installed.error ? [failure('reference', file, `cannot read the installed bundle: ${installed.error}`)] : []),
    ...(installed.integrity && installed.integrity !== actual ? [failure('reference', file,
      `${actual} is not the installed @scalar/api-reference bundle (${installed.integrity})`)] : []),
  ];
}

function referenceCheck(ctx) {
  const page = `${ctx.copy}/${REFERENCE_PAGE}`;
  if (!ctx.fileSet.has(page)) return { failures: [failure('reference', page, 'is missing')], checked: 1 };
  const { html, tokens } = ctx.page(page);
  const problems = [...cspProblems(html, tokens), ...scriptProblems(tokens)];
  return { failures: [...problems.map((detail) => failure('reference', page, detail)),
    ...bundleFailures(ctx, page, tokens)], checked: 1 };
}

// options: outDir (the /fieldset/ tree), copy (its minor, 'dev', or null without a copy), root (whether the root was
// built), mode ('dev', 'publish' or 'check'; `check` skips the reference checks), specSourceDir (the source's
// spec/form-definition/1.0, required with the root), literals (default DEFAULT_LITERALS; pass
// [...DEFAULT_LITERALS, ...more] to add), packageDir (docs/site, whose node_modules holds the pinned Scalar).
// Returns { failures: [{ check, file, detail }], skipped: [check], counts: { check: number checked } }.
export function runChecks(options) {
  const ctx = checkContext(options);
  const fragments = fragmentCheck(ctx);
  const results = {
    files: { failures: ctx.irregular, checked: ctx.files.length + ctx.irregular.length },
    fragments,
    literals: literalCheck(ctx),
    spec: ctx.root ? specCheck(ctx) : null,
    screenshots: ctx.root ? screenshotCheck(ctx) : null,
    fallback: ctx.root ? fallbackCheck(ctx) : null,
    reference: ctx.copy !== null && ctx.mode !== 'check' ? referenceCheck(ctx) : null,
  };
  const ran = Object.entries(results).filter(([, result]) => result !== null);
  return {
    failures: ran.flatMap(([, result]) => result.failures),
    skipped: Object.keys(results).filter((name) => results[name] === null),
    counts: { ...Object.fromEntries(ran.map(([name, result]) => [name, result.checked])),
      fragmentsSkipped: fragments.skipped },
  };
}

export const formatFailures = (failures) => failures.map(({ check, file, detail }) => `[${check}] ${file}: ${detail}`)
  .join('\n');

// runChecks, throwing when any check fails; returns the result otherwise.
export function assertChecks(options) {
  const result = runChecks(options);
  if (result.failures.length) {
    throw new Error(`Post-build checks failed (${result.failures.length}):\n${formatFailures(result.failures)}`);
  }
  return result;
}
