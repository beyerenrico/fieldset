// Fails when an installed npm package uses a license that is not OSI-approved and AGPL-compatible.
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

const violations = [];
let count = 0;
for (const manifest of packages('node_modules')) {
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
console.log(`npm licenses OK (${count} packages)`);
