// A minimal JSON Schema 2020-12 validator for the keywords schema/versions.schema.json uses (plan U5). docs/site has no
// validator dependency and the contract is small. KEYWORDS lists what this file implements; the schema test fails on
// any other keyword, so the schema cannot grow a rule that this validator would silently skip.
export const KEYWORDS = new Set([
  '$schema', '$id', '$comment', 'title', 'description', '$defs', '$ref',
  'type', 'const', 'required', 'properties', 'items', 'minItems', 'pattern',
]);

const isRecord = (value) => value !== null && typeof value === 'object' && !Array.isArray(value);
const TYPES = {
  object: isRecord,
  array: Array.isArray,
  string: (value) => typeof value === 'string',
  boolean: (value) => typeof value === 'boolean',
  integer: Number.isInteger,
};

function resolveRef(root, ref) {
  const match = /^#\/\$defs\/([\w-]+)$/.exec(ref);
  if (!match || !root.$defs?.[match[1]]) throw new Error(`Unsupported or unknown $ref ${ref}`);
  return root.$defs[match[1]];
}

function arrayErrors(value, schema, root, at) {
  const errors = value.length < (schema.minItems ?? 0) ? [`${at}: fewer than ${schema.minItems} items`] : [];
  if (schema.items) value.forEach((item, i) => errors.push(...validate(item, schema.items, root, `${at}[${i}]`)));
  return errors;
}

function objectErrors(value, schema, root, at) {
  const missing = (schema.required ?? []).filter((key) => !Object.hasOwn(value, key));
  const errors = missing.map((key) => `${at}: missing ${key}`);
  for (const [key, sub] of Object.entries(schema.properties ?? {})) {
    if (Object.hasOwn(value, key)) errors.push(...validate(value[key], sub, root, `${at}.${key}`));
  }
  return errors;
}

// Returns the list of violations; empty means valid.
export function validate(value, schema, root = schema, at = '$') {
  const errors = schema.$ref ? validate(value, resolveRef(root, schema.$ref), root, at) : [];
  if (schema.type && !TYPES[schema.type](value)) return [...errors, `${at}: expected ${schema.type}`];
  if ('const' in schema && value !== schema.const) errors.push(`${at}: expected ${JSON.stringify(schema.const)}`);
  const matches = (pattern) => typeof value !== 'string' || new RegExp(pattern, 'u').test(value);
  if (schema.pattern && !matches(schema.pattern)) errors.push(`${at}: does not match ${schema.pattern}`);
  if (Array.isArray(value)) return [...errors, ...arrayErrors(value, schema, root, at)];
  return isRecord(value) ? [...errors, ...objectErrors(value, schema, root, at)] : errors;
}

// Every keyword used at a schema position (not property or definition names).
export function keywordsOf(schema) {
  const nested = [
    ...Object.values(schema.$defs ?? {}), ...Object.values(schema.properties ?? {}),
    ...(schema.items ? [schema.items] : []),
  ];
  return [...new Set([...Object.keys(schema), ...nested.flatMap(keywordsOf)])];
}
