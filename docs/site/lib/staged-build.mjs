// What the two VitePress builds (the version copy, U3, and the root, U4) share: both build a staged dir that may lie
// outside docs/site (a tmp dir), and VitePress loads each config file itself, calling its default export without
// arguments, so the staged dir reaches the config only through the environment.
import { createHash } from 'node:crypto';
import { mkdirSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join, resolve } from 'node:path';
import { setTimeout as sleep } from 'node:timers/promises';
import { build, disposeMdItInstance } from 'vitepress';

export const STAGE_ENV = 'FIELDSET_DOCS_STAGE';
const BARE_IMPORT = /^(?![./\\\0]|[a-z][\w+.-]*:)/i;
const LOCK_POLL_MS = 200;
const LOCK_TIMEOUT_MS = 15 * 60 * 1000;

// `vue/server-renderer` and the other bare imports of the compiled pages cannot be found from a staged dir outside
// docs/site. They resolve as if the config file (importer) imported them, so pages and theme share one Vue.
export function stagedImports(srcDir, importer) {
  return {
    name: 'fieldset:staged-page-imports',
    enforce: 'pre',
    resolveId(source, from, options) {
      if (!from?.startsWith(`${srcDir}/`) || !BARE_IMPORT.test(source)) return null;
      return this.resolve(source, importer, { ...options, skipSelf: true });
    },
  };
}

// The staged dir the config of the running build reads.
export function stageDirFromEnv() {
  const stageDir = process.env[STAGE_ENV];
  if (!stageDir) throw new Error(`${STAGE_ENV} must name the staged dir to build`);
  return stageDir;
}

// VitePress writes a build's server bundle to <root>/.vitepress/.temp and deletes that dir when the build ends, so two
// builds of one project in two processes (test files run in parallel) would render each other's pages or lose them
// mid-build. A lock dir per project, in the OS tmp dir, holding the builder's pid, serializes them; a lock whose
// process is gone is taken over.
export const buildLockDir = (root) => join(tmpdir(),
  `fieldset-docs-build-${createHash('sha256').update(resolve(root)).digest('hex').slice(0, 16)}.lock`);

function lockHolder(lock) {
  try {
    return Number.parseInt(readFileSync(join(lock, 'pid'), 'utf8'), 10);
  } catch {
    return null; // just created, its pid not yet written
  }
}

function holderGone(pid) {
  if (!Number.isInteger(pid) || pid <= 0) return false;
  try {
    process.kill(pid, 0);
    return false;
  } catch (error) {
    return error.code === 'ESRCH';
  }
}

async function lockProject(root) {
  const lock = buildLockDir(root);
  const deadline = Date.now() + LOCK_TIMEOUT_MS;
  for (;;) {
    try {
      mkdirSync(lock);
      writeFileSync(join(lock, 'pid'), String(process.pid));
      return () => rmSync(lock, { recursive: true, force: true });
    } catch (error) {
      if (error.code !== 'EEXIST') throw error;
    }
    const pid = lockHolder(lock);
    if (pid === process.pid) throw new Error(`A build of ${root} is already running in this process`);
    if (holderGone(pid)) rmSync(lock, { recursive: true, force: true });
    else if (Date.now() > deadline) throw new Error(`Gave up waiting for another build of ${root} (${lock})`);
    else await sleep(LOCK_POLL_MS);
  }
}

// Builds the VitePress project at root (the dir holding .vitepress/) from stageDir into outDir. STAGE_ENV is set for
// the duration of this one build, so builds run one after the other, never concurrently in one process; builds of one
// project in other processes wait for each other. VitePress keeps one markdown renderer per process and hands it to
// every later build, whose pages would then be rendered with the first build's site (its links and text-only rules
// would not match them), so each build starts and ends without one.
export async function buildStaged({ root, stageDir, outDir }) {
  const unlock = await lockProject(root);
  const previous = process.env[STAGE_ENV];
  process.env[STAGE_ENV] = resolve(stageDir);
  disposeMdItInstance();
  try {
    await build(root, { outDir: resolve(outDir) });
  } finally {
    disposeMdItInstance();
    if (previous === undefined) delete process.env[STAGE_ENV];
    else process.env[STAGE_ENV] = previous;
    unlock();
  }
}
