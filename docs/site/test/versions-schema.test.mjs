// schema/versions.schema.json describes versions.json schema 1 (plan U5, KTD "versions.json schema 1 is frozen"): the
// writer's output validates for every publish scenario, and the schema's patterns accept exactly the release grammar
// of the shared vectors file. Validation uses the minimal validator in support/json-schema.mjs.
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { KEYWORDS, keywordsOf, validate } from './support/json-schema.mjs';
import { WRITER_OUTPUTS } from './support/versions-scenarios.mjs';

const SCHEMA = JSON.parse(readFileSync(new URL('../schema/versions.schema.json', import.meta.url), 'utf8'));
const VECTORS = JSON.parse(readFileSync(new URL('./fixtures/version-vectors.json', import.meta.url), 'utf8'));

test('the schema is 2020-12 and uses only keywords the test validator implements', () => {
  assert.equal(SCHEMA.$schema, 'https://json-schema.org/draft/2020-12/schema');
  assert.deepEqual(keywordsOf(SCHEMA).filter((keyword) => !KEYWORDS.has(keyword)), []);
});

test('the writer output validates for every publish scenario', () => {
  for (const [name, versions] of WRITER_OUTPUTS) assert.deepEqual(validate(versions, SCHEMA), [], name);
});

test('unknown fields stay valid: schema 1 is additive only', () => {
  const [, versions] = WRITER_OUTPUTS[0];
  const future = { ...versions, extra: true, copies: versions.copies.map((copy) => ({ ...copy, extra: 1 })) };
  assert.deepEqual(validate(future, SCHEMA), []);
});

test('the schema rejects broken files, so the validation above means something', () => {
  const [, versions] = WRITER_OUTPUTS[1];
  const [first] = versions.copies;
  const { tag, ...untagged } = first;
  const broken = {
    'schema 2': { ...versions, schema: 2 },
    'no latest': { schema: 1, copies: versions.copies },
    'latest not a minor': { ...versions, latest: 'javascript:alert(1)' },
    'no copies': { ...versions, copies: [] },
    'minor with a path': { ...versions, copies: [{ ...first, minor: '//evil.example' }] },
    'prerelease as text': { ...versions, copies: [{ ...first, prerelease: 'true' }] },
    'short builtWith': { ...versions, copies: [{ ...first, builtWith: 'abc123' }] },
    'missing tag': { ...versions, copies: [untagged] },
    'tag without v': { ...versions, copies: [{ ...first, tag: tag.slice(1) }] },
  };
  for (const [name, file] of Object.entries(broken)) assert.notDeepEqual(validate(file, SCHEMA), [], name);
});

test('the version and tag patterns accept the release grammar of the vectors and nothing else', () => {
  const { tag, version } = SCHEMA.$defs.copy.properties;
  const tagPattern = new RegExp(SCHEMA.$defs[tag.$ref.split('/').pop()].pattern, 'u');
  const versionPattern = new RegExp(SCHEMA.$defs[version.$ref.split('/').pop()].pattern, 'u');
  for (const valid of VECTORS.ascending) {
    assert.match(valid, tagPattern);
    assert.match(valid.slice(1), versionPattern);
  }
  for (const invalid of VECTORS.invalid) {
    assert.doesNotMatch(invalid, tagPattern, JSON.stringify(invalid));
    if (invalid.startsWith('v')) assert.doesNotMatch(invalid.slice(1), versionPattern, JSON.stringify(invalid));
  }
});
