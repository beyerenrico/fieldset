// What the two VitePress builds (the version copy, U3, and the root, U4) share: both build a staged dir that may lie
// outside docs/site (a tmp dir), and VitePress loads each config file itself, calling its default export without
// arguments, so the staged dir reaches the config only through the environment.
import { resolve } from 'node:path';
import { build } from 'vitepress';

export const STAGE_ENV = 'FIELDSET_DOCS_STAGE';
const BARE_IMPORT = /^(?![./\\\0]|[a-z][\w+.-]*:)/i;

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

// Builds the VitePress project at root (the dir holding .vitepress/) from stageDir into outDir. STAGE_ENV is set for
// the duration of this one build, so builds run one after the other, never concurrently in one process.
export async function buildStaged({ root, stageDir, outDir }) {
  const previous = process.env[STAGE_ENV];
  process.env[STAGE_ENV] = resolve(stageDir);
  try {
    await build(root, { outDir: resolve(outDir) });
  } finally {
    if (previous === undefined) delete process.env[STAGE_ENV];
    else process.env[STAGE_ENV] = previous;
  }
}
