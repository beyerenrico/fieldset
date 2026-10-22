// License gate for the docs site package (plan U1, R17): scripts/check-licenses.mjs scans a given directory's
// node_modules, and docs/site never runs install scripts.
import { after, test } from 'node:test';
import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import { existsSync, mkdirSync, mkdtempSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { fileURLToPath } from 'node:url';

const repoRoot = fileURLToPath(new URL('../../../', import.meta.url));
const siteDir = fileURLToPath(new URL('../', import.meta.url));
const script = join(repoRoot, 'scripts', 'check-licenses.mjs');
const temporary = [];

after(() => temporary.forEach((dir) => rmSync(dir, { recursive: true, force: true })));

function tempDir() {
  const dir = mkdtempSync(join(tmpdir(), 'fieldset-licenses-'));
  temporary.push(dir);
  return dir;
}

// packages maps an install path below node_modules (nested ones included) to its license field.
function fixture(packages) {
  const dir = tempDir();
  for (const [path, license] of Object.entries(packages)) {
    const packageDir = join(dir, 'node_modules', path);
    mkdirSync(packageDir, { recursive: true });
    const name = path.split('/node_modules/').at(-1);
    writeFileSync(join(packageDir, 'package.json'), JSON.stringify({ name, version: '1.0.0', license }));
  }
  return dir;
}

const checkLicenses = (args, cwd = repoRoot) =>
  spawnSync(process.execPath, [script, ...args], { cwd, encoding: 'utf8' });
const packageCount = (stdout) => Number(/OK \((\d+) packages/.exec(stdout)?.[1]);

test('a directory with MIT and ISC packages passes', () => {
  const dir = fixture({ 'left-pad': 'MIT', '@scope/util': 'ISC' });
  const run = checkLicenses([dir]);
  assert.equal(run.status, 0, run.stderr);
  assert.match(run.stdout, /npm licenses OK \(2 packages in /);
});

test('a disallowed license fails and names the package, also when nested', () => {
  const dir = fixture({ 'left-pad': 'MIT', '@scope/util': 'ISC', '@scope/util/node_modules/copyleft': 'SSPL-1.0' });
  const run = checkLicenses([dir]);
  assert.equal(run.status, 1);
  assert.match(run.stderr, /copyleft@1\.0\.0: "SSPL-1\.0"/);
  assert.doesNotMatch(run.stderr, /left-pad|@scope\/util@/);
});

test('a dual license takes the allowed option', () => {
  const dir = fixture({ 'dual-licensed': '(MIT OR CC0-1.0)', 'one-allowed': '(GPL-3.0-only OR MIT)' });
  const run = checkLicenses([dir]);
  assert.equal(run.status, 0, run.stderr);
});

test('a directory without node_modules fails instead of passing empty', () => {
  const run = checkLicenses([tempDir()]);
  assert.equal(run.status, 1);
  assert.match(run.stderr, /No node_modules in /);
});

test('without an argument it scans ./node_modules of the working directory', () => {
  const run = checkLicenses([], fixture({ copyleft: 'SSPL-1.0' }));
  assert.equal(run.status, 1);
  assert.match(run.stderr, /copyleft@1\.0\.0/);
});

test('without an argument from the repo root it scans the root packages as before', () => {
  // The root node_modules exists only after a root `npm ci`; without it the default run passes empty, as it always did.
  const installed = existsSync(join(repoRoot, 'node_modules'));
  const expected = installed ? packageCount(checkLicenses([repoRoot]).stdout) : 0;
  const run = checkLicenses([]);
  assert.equal(run.status, 0, run.stderr);
  assert.match(run.stdout, /^npm licenses OK \(\d+ packages\)$/m);
  assert.equal(packageCount(run.stdout), expected);
});

test('docs/site sets ignore-scripts in its own .npmrc', () => {
  // Only the project config may set it: drop inherited npm_config_* variables and use empty user and global files.
  const configDir = tempDir();
  const [userconfig, globalconfig] = ['user', 'global'].map((scope) => join(configDir, `${scope}.npmrc`));
  [userconfig, globalconfig].forEach((file) => writeFileSync(file, ''));
  const env = Object.fromEntries(Object.entries(process.env).filter(([key]) => !/^npm_config_/i.test(key)));
  const run = spawnSync('npm', ['config', 'get', 'ignore-scripts'], {
    cwd: siteDir,
    encoding: 'utf8',
    env: { ...env, npm_config_userconfig: userconfig, npm_config_globalconfig: globalconfig },
  });
  assert.equal(run.status, 0, run.stderr);
  assert.equal(run.stdout.trim(), 'true');
});
