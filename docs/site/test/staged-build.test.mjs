// Builds of one VitePress project share its .vitepress/.temp, so buildStaged (lib/staged-build.mjs) holds a lock per
// project across processes: a lock left by a process that is gone is taken over, a second build of the project in the
// same process fails at once instead of waiting for itself, and the lock is released when a build fails.
import { after, describe, test } from 'node:test';
import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import { existsSync, mkdirSync, rmSync, writeFileSync } from 'node:fs';
import { join } from 'node:path';
import { buildLockDir, buildStaged } from '../lib/staged-build.mjs';
import { cleanup, tempDir, writeTree } from './support/site.mjs';

after(cleanup);

// A project whose config fails as soon as VitePress loads it, so no build output is written anywhere.
function failingProject() {
  const root = writeTree(tempDir('fieldset-lock-'), {
    '.vitepress/config.mjs': "export default () => { throw new Error('fixture config fails'); };\n",
  });
  return { root, stageDir: tempDir(), outDir: join(tempDir(), 'out') };
}

function holdLock(root, pid) {
  mkdirSync(buildLockDir(root));
  writeFileSync(join(buildLockDir(root), 'pid'), String(pid));
}

describe('the build lock', () => {
  test('is released when the build fails', async () => {
    const project = failingProject();
    await assert.rejects(buildStaged(project), /fixture config fails/);
    assert.ok(!existsSync(buildLockDir(project.root)));
  });

  test('left by a process that is gone is taken over', async () => {
    const project = failingProject();
    const gone = spawnSync(process.execPath, ['-e', '']).pid;
    holdLock(project.root, gone);
    await assert.rejects(buildStaged(project), /fixture config fails/);
    assert.ok(!existsSync(buildLockDir(project.root)));
  });

  test('held by this process fails a second build at once', async () => {
    const project = failingProject();
    holdLock(project.root, process.pid);
    await assert.rejects(buildStaged(project), /A build of .* is already running in this process/);
    assert.ok(existsSync(buildLockDir(project.root)), 'the holder keeps its lock');
    rmSync(buildLockDir(project.root), { recursive: true });
  });
});
