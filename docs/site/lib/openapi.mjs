// The interactive API reference of one version copy (plan U11, R2, R22; KTD "Interactive reference: vendored Scalar
// standalone page"). It takes an OpenAPI document exported from the release's code, checks it, points its servers at a
// {server} variable, and writes two static files into the staged dir's public/, which VitePress copies into the copy:
//
//   <stage>/public/api-reference.html        the page: the CSP below, the document embedded as a JSON data block,
//                                             Scalar's options in that block's data-configuration attribute
//   <stage>/public/scalar-api-reference.js   Scalar's standalone browser bundle, byte for byte from node_modules
//                                             (lockfile-checked, never committed, never a CDN; ADR 0040)
//
// The page fetches nothing: connect-src 'none' and form-action 'none' stop every request Scalar's client could send,
// whatever its options say. Scalar injects its CSS as <style> elements and draws icons as data: images, hence
// style-src 'unsafe-inline' and img-src data:. No inline script runs: the data block is JSON, and the bundle's
// HTML API mounts the reference from it. The script tag carries the bundle's sha256 as integrity, which U12 checks.
//
// Export (U13, U6), against a migrated database, with the version the copy shows:
//   API_VERSION=<version> php artisan scramble:export --path=<file>
// inside the released app image for a publish, with the local PHP setup in CI. config/scramble.php derives servers
// from APP_URL; prepareDocument replaces them. Then: writeReferencePage({ stageDir, exportFile: <file> }).
import { createHash } from 'node:crypto';
import { copyFileSync, mkdirSync, readFileSync, writeFileSync } from 'node:fs';
import { join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { DESCRIPTOR_FILE, validateDescriptor } from './stage.mjs';

export const REFERENCE_PAGE = 'api-reference.html';
export const SCALAR_BUNDLE = 'scalar-api-reference.js';
export const REFERENCE_CSP = "default-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; "
  + "connect-src 'none'; form-action 'none'";
// The v1 routes tests/Feature/Api/OpenApiDocsTest.php expects in the generated document.
export const REQUIRED_PATHS = Object.freeze(['/me', '/forms', '/submissions/{uuid}', '/submissions/{uuid}/submit',
  '/auth/device-status']);
export const SERVER_URL = 'https://{server}/api/v1';
export const SERVER_DEFAULT = 'fieldset.example.com';

// Option names of @scalar/api-reference 1.75.0 (@scalar/schemas api-reference/base-configuration.js,
// api-reference-configuration.js, source-configuration.js). Not set on purpose: url (the document is embedded),
// proxyUrl (requests are never proxied), pluginUrls (no module is imported).
export const SCALAR_OPTIONS = Object.freeze({
  telemetry: false,
  withDefaultFonts: false, // would load fonts from fonts.scalar.com
  persistAuth: false, // no credentials in localStorage
  hideTestRequestButton: true, // the "Test Request" button of each operation
  hideClientButton: true, // the button that opens the API client
  showDeveloperTools: 'never', // the developer toolbar, shown on localhost by default
  agent: Object.freeze({ disabled: true }), // Agent Scalar (AI chat), enabled on localhost by default
  mcp: Object.freeze({ disabled: true }),
});

const SITE_DIR = fileURLToPath(new URL('../', import.meta.url));
const SCALAR_PACKAGE = '@scalar/api-reference';
// The self-contained UMD build (the package's `browser` entry); the ESM build loads chunks.
const SCALAR_BUNDLE_SOURCE = 'dist/browser/standalone.js';

const isObject = (value) => typeof value === 'object' && value !== null && !Array.isArray(value);
const HTTP_METHODS = ['get', 'put', 'post', 'delete', 'options', 'head', 'patch', 'trace'];

function endsInApiV1(server) {
  if (!isObject(server) || typeof server.url !== 'string') return false;
  try {
    return new URL(server.url, 'http://localhost').pathname.replace(/\/+$/, '').endsWith('/api/v1');
  } catch {
    return false;
  }
}

// Paths are relative to the server URL, so the export's server must end in /api/v1 for the rewrite to keep them right.
function serverProblems(servers) {
  if (!Array.isArray(servers) || servers.length === 0) return ['servers is missing'];
  return servers.filter((server) => !endsInApiV1(server))
    .map((server) => `server ${JSON.stringify(server)} does not end in /api/v1, which the paths are relative to`);
}

// Servers below the document level would escape the rewrite to the {server} variable.
function nestedServerProblems(paths) {
  return Object.entries(paths).flatMap(([path, item]) => {
    if (!isObject(item)) return [`paths[${JSON.stringify(path)}] is not an object`];
    const own = item.servers === undefined ? [] : [`paths[${JSON.stringify(path)}] declares its own servers`];
    return [...own, ...HTTP_METHODS.filter((method) => isObject(item[method]) && item[method].servers !== undefined)
      .map((method) => `${method.toUpperCase()} ${path} declares its own servers`)];
  });
}

export function openApiProblems(document) {
  if (!isObject(document)) return ['the document is not a JSON object'];
  const problems = [];
  if (typeof document.openapi !== 'string' || !/^3\.1\.\d+$/.test(document.openapi)) {
    problems.push(`openapi is ${JSON.stringify(document.openapi)}, expected 3.1.x`);
  }
  if (!isObject(document.info) || typeof document.info.title !== 'string' || typeof document.info.version !== 'string') {
    problems.push('info.title and info.version must be strings');
  }
  if (!isObject(document.paths)) return [...problems, 'paths is missing'];
  const missing = REQUIRED_PATHS.filter((path) => !Object.hasOwn(document.paths, path));
  if (missing.length) problems.push(`missing path(s): ${missing.join(', ')}`);
  return [...problems, ...serverProblems(document.servers), ...nestedServerProblems(document.paths)];
}

// The document the page embeds: the export, checked, with its servers replaced. The input is not modified.
export function prepareDocument(document) {
  const problems = openApiProblems(document);
  if (problems.length) throw new Error(`The OpenAPI export is not usable:\n  ${problems.join('\n  ')}`);
  const server = {
    url: SERVER_URL,
    variables: { server: { default: SERVER_DEFAULT, description: 'Host name of your Fieldset instance' } },
  };
  return { ...document, servers: [server] };
}

export function readExport(exportFile) {
  try {
    return JSON.parse(readFileSync(exportFile, 'utf8'));
  } catch (error) {
    throw new Error(`Cannot read the OpenAPI export ${exportFile}: ${error.message}`);
  }
}

// The installed bundle and its digests; the version must be the one package.json pins (npm ci from the lockfile).
export function scalarBundle(siteDir = SITE_DIR) {
  const packageDir = join(siteDir, 'node_modules', SCALAR_PACKAGE);
  const { version } = JSON.parse(readFileSync(join(packageDir, 'package.json'), 'utf8'));
  const pinned = JSON.parse(readFileSync(join(siteDir, 'package.json'), 'utf8')).devDependencies?.[SCALAR_PACKAGE];
  if (version !== pinned) {
    throw new Error(`${SCALAR_PACKAGE} ${version} is installed but package.json pins ${pinned}; run npm ci in docs/site`);
  }
  const path = join(packageDir, SCALAR_BUNDLE_SOURCE);
  const digest = createHash('sha256').update(readFileSync(path)).digest();
  return { path, version, sha256: digest.toString('hex'), integrity: `sha256-${digest.toString('base64')}` };
}

// In JSON text < > & occur only inside strings, where a unicode escape stands for the same character: the escaped text
// parses to the same value and holds no `</script` or `<!--` that could end the data block or change how it parses.
const SCRIPT_UNSAFE = /[<>&]/g;
export const jsonForScript = (value) => JSON.stringify(value)
  .replace(SCRIPT_UNSAFE, (char) => `\\u00${char.charCodeAt(0).toString(16)}`);

const ENTITIES = { '&': '&amp;', '"': '&quot;', "'": '&#39;', '<': '&lt;', '>': '&gt;' };
export const escapeHtml = (text) => text.replace(/[&"'<>]/g, (char) => ENTITIES[char]);

// The CSP is written as the raw literal (it holds no character that needs escaping in a double-quoted attribute), so
// U12 and the store job can match it byte for byte.
export function referencePage({ document, descriptor, integrity }) {
  const label = descriptor.minor === 'dev' ? 'development build' : descriptor.minor;
  const configuration = escapeHtml(JSON.stringify(SCALAR_OPTIONS));
  return [
    '<!doctype html>',
    '<html lang="en">',
    '<head>',
    '<meta charset="utf-8">',
    `<meta http-equiv="Content-Security-Policy" content="${REFERENCE_CSP}">`,
    '<meta name="viewport" content="width=device-width, initial-scale=1">',
    `<title>API reference | Fieldset ${escapeHtml(label)}</title>`,
    `<meta name="description" content="Interactive reference of the Fieldset REST API (/api/v1), ${escapeHtml(label)}.">`,
    '</head>',
    '<body>',
    '<noscript><p>The interactive API reference needs JavaScript. The <a href="field-app-api-v1">Field App API '
      + 'contract</a> describes the same API.</p></noscript>',
    `<script id="api-reference" type="application/json" data-configuration="${configuration}">`
      + `${jsonForScript(document)}</script>`,
    `<script src="${SCALAR_BUNDLE}" integrity="${integrity}"></script>`,
    '</body>',
    '</html>',
    '',
  ].join('\n');
}

// stageDir: a dir staged by stageContent (lib/stage.mjs), whose descriptor names the copy; exportFile: the scramble
// export. Checks the export before writing anything. Returns the written files, the bundle's sha256 and the paths.
export function writeReferencePage({ stageDir, exportFile, siteDir = SITE_DIR }) {
  const descriptor = validateDescriptor(JSON.parse(readFileSync(join(stageDir, DESCRIPTOR_FILE), 'utf8')));
  const document = prepareDocument(readExport(exportFile));
  const bundle = scalarBundle(siteDir);
  const publicDir = join(stageDir, 'public');
  mkdirSync(publicDir, { recursive: true });
  copyFileSync(bundle.path, join(publicDir, SCALAR_BUNDLE));
  writeFileSync(join(publicDir, REFERENCE_PAGE), referencePage({ document, descriptor, integrity: bundle.integrity }));
  return {
    page: join(publicDir, REFERENCE_PAGE),
    bundle: join(publicDir, SCALAR_BUNDLE),
    sha256: bundle.sha256,
    paths: Object.keys(document.paths),
  };
}
