// Fails when an installed npm package uses a license that is not OSI-approved and AGPL-compatible.
// Scans <dir>/node_modules for an optional argument such as docs/site (its own package, ADR 0094), else ./node_modules
// (the repo root in CI and `npm run licenses`).
import { readdirSync, readFileSync, existsSync } from 'node:fs';
import { join } from 'node:path';

const ALLOWED = new Set([
  'MIT', 'MIT-0', 'BSD-2-Clause', 'BSD-3-Clause', '0BSD', 'ISC', 'Apache-2.0', 'MPL-2.0',
  'BlueOak-1.0.0', 'CC0-1.0', 'Unlicense', 'Python-2.0', 'CC-BY-4.0', 'LGPL-3.0-or-later', 'Zlib',
]);

function* packages(dir) {
  if (!existsSync(dir)) return;
  for (const entry of readdirSync(dir, { withFileTypes: true })) {
    if (!entry.isDirectory() || entry.name.startsWith('.')) continue;
    const path = join(dir, entry.name);
    if (entry.name.startsWith('@')) { yield* packages(path); continue; }
    const manifest = join(path, 'package.json');
    if (existsSync(manifest)) yield manifest;
    yield* packages(join(path, 'node_modules'));
  }
}

const accepts = (expr) => String(expr)
  .replace(/[()]/g, ' ')
  .split(/\s+OR\s+/i)
  .some((alt) => alt.split(/\s+AND\s+/i).every((id) => ALLOWED.has(id.trim())));

const [dir] = process.argv.slice(2);
const root = join(dir ?? '.', 'node_modules');
if (dir !== undefined && !existsSync(root)) {
  console.error(`No node_modules in ${dir}: install it first (npm ci).`);
  process.exit(1);
}

const violations = [];
let count = 0;
for (const manifest of packages(root)) {
  const pkg = JSON.parse(readFileSync(manifest, 'utf8'));
  if (!pkg.name || !pkg.version) continue;
  count++;
  const license = pkg.license ?? (Array.isArray(pkg.licenses) ? pkg.licenses.map((l) => l.type).join(' OR ') : undefined);
  if (!license || !accepts(typeof license === 'object' ? license.type : license)) {
    violations.push(`${pkg.name}@${pkg.version}: ${JSON.stringify(license ?? 'none')}`);
  }
}

if (violations.length) {
  console.error(`Disallowed npm licenses:\n  ${[...new Set(violations)].join('\n  ')}`);
  process.exit(1);
}
console.log(`npm licenses OK (${count} packages${dir === undefined ? '' : ` in ${dir}`})`);
