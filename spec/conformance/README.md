# Conformance suite — Fieldset Spec 1.0

Both reference implementations (PHP `packages/fieldset-php`, TypeScript `packages/fieldset-js`)
run **every** case here; CI fails on any difference. Cases are plain JSON so other tools can reuse them.

| Folder | File shape | Assertion |
|---|---|---|
| `logic/` | `{ description, tests: [{ description, logic, data, expected }] }` | `evaluate(logic, data)` deep-equals `expected` (`data` is the scope object) |
| `evaluate/` | `{ description, definition, context, tests: [{ description, data, expected }] }` | see below |
| `lint/` | `{ description, tests: [{ description, definition, published?, expected }] }` | lint findings as a set of `(severity, code, path)` equal `expected` |
| `meta-schema/` | `{ description, tests: [{ description, definition, valid }] }` | meta-schema validation result equals `valid` |
| `checksum/` | `{ description, tests: [{ description, value, canonical, sha256 }] }` | canonical JSON string and SHA-256 hex match |

`evaluate` expectations (all optional except `errors`):

- `errors`: set of `{pointer, code}`; must match exactly (order-insensitive). `params` are compared only
  when present in the expectation.
- `data`: deep-equals the evaluator's output data.
- `calculated`: map pointer → value; each listed pointer must hold that value in the output data
  (`null` also matches a missing key).
- `visibility`: map pointer → boolean; each listed pointer must have that visibility.

`context` supplies built-ins: `today`, `now`, `user {name, email}`, `org {name}`, `formVersion`.

Add a case whenever semantics change, in the same commit as the spec change (AGENTS.md).
