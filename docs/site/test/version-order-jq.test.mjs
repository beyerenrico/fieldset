// The store job's jq ordering program (plan U5, KTD "Version rules decide every publish"): it runs the shared vectors
// restricted to copy tags (vX.Y.Z and vX.Y.Z-rc.N) and must agree with them; every other tag stops it with exit
// status 5. The store job runs git, jq, grep and cmp only, so this program is its sole view of the order.
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { execFile } from 'node:child_process';
import { readFileSync } from 'node:fs';
import { availableParallelism } from 'node:os';
import { fileURLToPath } from 'node:url';
import { promisify } from 'node:util';

const PROGRAM = fileURLToPath(new URL('../store/version-order.jq', import.meta.url));
const VECTORS = JSON.parse(readFileSync(new URL('./fixtures/version-vectors.json', import.meta.url), 'utf8'));
const COPY_TAGS = VECTORS.ascending.filter((tag) => !VECTORS.noCopy.includes(tag));

const execJq = promisify(execFile);
async function jqOrder(a, b) {
  try {
    const { stdout, stderr } = await execJq('jq', ['-n', '--arg', 'a', a, '--arg', 'b', b, '-f', PROGRAM]);
    return { status: 0, stdout, stderr };
  } catch (error) {
    if (typeof error.code !== 'number') throw error; // jq missing: CI's ubuntu runner and dev machines have it
    return { status: error.code, stdout: error.stdout, stderr: error.stderr };
  }
}
// About a thousand jq runs: in parallel, results in input order.
async function jqOrders(pairs) {
  const results = [];
  let next = 0;
  const worker = async () => {
    while (next < pairs.length) {
      const index = next++;
      results[index] = await jqOrder(...pairs[index]);
    }
  };
  await Promise.all(Array.from({ length: availableParallelism() }, worker));
  return results;
}

test('the copy tags of the chain are finals and rc.N pre-releases only', () => {
  assert.ok(COPY_TAGS.length > 25);
  for (const tag of COPY_TAGS) assert.match(tag, /^v\d+\.\d+\.\d+(-rc\.\d+)?$/);
});

test('jq orders every pair of copy tags as the chain does and prints -1, 0 or 1', async () => {
  const pairs = COPY_TAGS.flatMap((a, i) => COPY_TAGS.map((b, j) => [a, b, Math.sign(i - j)]));
  const runs = await jqOrders(pairs);
  pairs.forEach(([a, b, order], index) => {
    assert.deepEqual(runs[index], { status: 0, stdout: `${order}\n`, stderr: '' }, `${a} against ${b}`);
  });
});

test('jq agrees with every named pair of copy tags', async () => {
  const pairs = VECTORS.pairs.filter(({ a, b }) => COPY_TAGS.includes(a) && COPY_TAGS.includes(b));
  assert.ok(pairs.length >= 8);
  const runs = await jqOrders(pairs.map(({ a, b }) => [a, b]));
  pairs.forEach(({ case: name, order }, index) => assert.equal(runs[index].stdout, `${order}\n`, name));
});

test('jq rejects no-copy and invalid tags in either position with exit status 5', async () => {
  const tags = [...VECTORS.noCopy, ...VECTORS.invalid];
  const runs = await jqOrders(tags.flatMap((tag) => [[tag, 'v2.1.0'], ['v2.1.0', tag]]));
  runs.forEach((run, index) => {
    const tag = JSON.stringify(tags[Math.floor(index / 2)]);
    assert.equal(run.status, 5, tag);
    assert.equal(run.stdout, '', tag);
    assert.match(run.stderr, /version-order: .* is not a docs copy tag/, tag);
  });
});
