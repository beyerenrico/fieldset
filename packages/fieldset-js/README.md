# @fieldset/fieldset-js

Reference TypeScript SDK for **Form Schema Spec 1.0**
([`spec/form-definition/1.0/README.md`](../../spec/form-definition/1.0/README.md)). It validates,
lints and evaluates Form Definitions and implements the JsonLogic dialect of the spec with its own
evaluator ([ADR 0021](../../docs/adr/0021-own-jsonlogic-evaluator.md)). Every case in
[`spec/conformance`](../../spec/conformance) passes. The PHP SDK must produce identical results.

The package is browser-safe: `src/` uses no Node APIs (an ESLint rule enforces this). Consumers are
Vite apps that import the TypeScript sources directly (`exports` → `./src/index.ts`). The meta-schema
and `roles.json` are imported from `spec/`, so the spec stays the single source.

## API

```ts
import {
    decodeJson, // JSON text → value (throws JsonDecodeError)
    canonicalJson, // canonical JSON (§6)
    checksum, // SHA-256 hex of the canonical JSON
    validateDefinition, // meta-schema (JSON Schema 2020-12) → { valid, errors[{path, message}] }
    evaluateLogic, // JsonLogic dialect (§4, §5.3); errors → null (evaluateLogicStrict throws)
    parseDefinition, // immutable model: nodes with scope + JSON paths
    lint, // §7 findings [{severity, code, path, params?}]
    evaluate, // §5.5 → { data, errors[{pointer, code, params?}], visibility }
    exportDataSchema, // §10 data contract (JSON Schema 2020-12)
} from '@fieldset/fieldset-js';

const result = evaluate(definition, submission, {
    today: '2026-10-16',
    now: '2026-10-16T09:41:00+02:00',
    user: { name: 'Willi Worker', email: 'worker@demo.test' },
    org: { name: 'Demo GmbH' },
    formVersion: 3,
});
const findings = lint(definition, publishedVersions); // key_locked / key_type_changed need history
```

`parseDefinition` returns a frozen `DefinitionModel` (`nodes` in pre-order, each with `path`
such as `/pages/0/elements/2`, its `scope`, `parent`, `children` and the repeater `rowScope`).
`evaluate`, `lint` and `exportDataSchema` accept raw JSON or a model. Inputs are never mutated.
Types for definitions (element union by `type`), submission data, errors, lint findings and the
context are exported as well.

## Scripts

| Script                | Purpose                                                                                                       |
| --------------------- | ------------------------------------------------------------------------------------------------------------- |
| `npm test`            | all unit and conformance tests (Vitest)                                                                       |
| `npm run conformance` | only `test/conformance.test.ts` (one test per case in `spec/conformance`)                                     |
| `npm run coverage`    | tests with V8 coverage (threshold 95 % lines on `src/`)                                                       |
| `npm run lint`        | ESLint (`typescript-eslint` strict type-checked + Prettier compatibility)                                     |
| `npm run typecheck`   | `tsc --noEmit` (strict, `noUncheckedIndexedAccess`, `exactOptionalPropertyTypes`)                             |
| `npm run format`      | Prettier                                                                                                      |
| `npm run build`       | `tsc` to `dist/` (optional; `rootDir` is the repository root because the spec JSON lives outside the package) |

## Dependencies

| Package                              | Version           | License    | Use                                                        |
| ------------------------------------ | ----------------- | ---------- | ---------------------------------------------------------- |
| `ajv` (`ajv/dist/2020`)              | ^8.20.0           | MIT        | meta-schema validation, `unevaluatedProperties`            |
| `decimal.js`                         | ^10.6.0           | MIT        | exact decimals, own clone: precision 1000, `ROUND_HALF_UP` |
| `@noble/hashes`                      | ^2.4.0            | MIT        | synchronous SHA-256                                        |
| `typescript`                         | ~6.0.3            | Apache-2.0 | dev                                                        |
| `vitest`, `@vitest/coverage-v8`      | ^5.0.5            | MIT        | dev                                                        |
| `eslint`, `@eslint/js`               | ^10.12.0, ^10.0.1 | MIT        | dev                                                        |
| `typescript-eslint`                  | ^8.71.2           | MIT        | dev                                                        |
| `prettier`, `eslint-config-prettier` | ^3.9.10, ^10.1.8  | MIT        | dev                                                        |
| `@types/node`                        | ^24               | MIT        | dev (tests only)                                           |
| `ajv-formats`                        | ^3.0.1            | MIT        | dev (tests validate the exported data schema)              |

**TypeScript version:** npm `latest` is 7.0.2, but `typescript-eslint` 8.71.2 declares the peer
range `typescript >=4.8.4 <6.1.0`. The package therefore pins the newest supported line,
**TypeScript 6.0.3** (`~6.0.3`). Move to 7.x once `typescript-eslint` supports it.

## Implementation notes

Most of these points are now normative in the spec README; the rest are SDK choices:

- **Patterns (§3.4):** a hand-written parser accepts exactly the restricted subset (no nested
  quantifiers on groups, bounds ≤ 1000, no backreferences, named groups, lookbehind, inline flags,
  property escapes) and re-emits the pattern for `new RegExp(source, 'u')`; the only rewrite is `\-`
  outside a class, which Unicode mode cannot parse escaped. An unescaped `[` inside a class is
  rejected (avoids POSIX-class ambiguity). Values longer than 4,096 code points fail `pattern`.
- **Limits:** decimal strings have at most 60 digits (money also after padding to its scale);
  `decodeJson` rejects documents nested deeper than 1,024 levels; logic deeper than 512 levels of
  objects and arrays (a scalar is depth 0, `{"!": true}` depth 1) evaluates to `null` and is linted
  as `logic_too_deep` without further checks of that property.
- **Calculation order (§5.5):** Kahn's algorithm over static dependencies with the ready set ordered
  by document order. A reference to a repeater depends on every calculated element in its rows
  (row calculations before aggregates; also used for `cycle`).
- **Meta-schema:** Ajv tracks evaluated properties in a plain object, so `Object.prototype` names
  such as `constructor` would slip past `unevaluatedProperties`; the element schema additionally
  forbids those names (`ownProperties: true` as well).

- **Rounds (§5.5 step 2):** visibility is decided top-down and each decision is visible to later
  elements of the same pass. A round counts as stable only if visibility _and_ the data (calculated
  values and applied defaults) are unchanged, because a default applied after the visibility pass can
  only change visibility in the next round. Hard cap: 5 rounds.
- **Normalization errors** (`type_mismatch` for non-array repeaters and non-object rows) are reported
  even when the repeater ends up invisible. A `null` repeater value counts as an empty value. Rows
  dropped as non-objects shift later row indexes.
- **Repeater `minItems`** applies to an empty or missing list (the "repeater below minItems" case);
  other empty values skip constraints unless the element is required.
- **Type checks:** `rating` has no type check; any non-integer or out-of-range value is
  `out_of_range` (and is not nulled in the logic view). Attachment shape errors are always
  `invalid_attachment`. Non-object signatures/locations are `type_mismatch`; objects must have exactly
  the documented members. `hidden` values that are not string/integer/boolean are replaced by the
  default when one applies, otherwise reported as `type_mismatch`.
- **Logic:** `==` converts arrays to their comma-joined string (also array vs array); objects are never
  equal. Numeric conversion of strings uses `Number(string)` (empty string = 0). `+ - * / %` results
  that are NaN or infinite become `null`. `min`/`max` use the aggregate form when the first operand is
  an array or the only operand, otherwise the variadic form; the result keeps the winning item's own
  scale and ties keep the first item. `dec.div`/`dec.round` scales must be JSON integers 0–20.
  `date.diff` on date-times includes fractional seconds before truncating; `date.add` drops them.
  `substr` counts code points; `in` converts a non-string needle like JavaScript `String()`.
  Unknown operators and nesting deeper than 512 levels make `evaluateLogic` return `null`;
  `evaluateLogicStrict` throws `LogicError` instead.
- **Unvalidated definitions:** out-of-range `scale` values (money outside 0–4, calculated outside
  0–10) fall back to the defaults instead of throwing.
- **Lint:** references must resolve to _data_ keys (layout keys are not data); built-in objects check
  their member (`$user.name|email`, `$org.name`, `$form.version`). `reserved_type` points at `/type`,
  `duplicate_id` at the element. Var references and `decimal_builtin_operator` are not checked inside
  per-item arguments of iteration operators. Whole-scope references (`""`, `$root`, `$row`) add no
  calculation dependencies.
- **Data contract (§10):** `time` uses a pattern instead of `format: time`, because JSON Schema's
  `time` format is RFC 3339 full-time with offset and would reject `HH:MM`. The schema describes
  non-empty values; empty values of optional elements (`null`, `""`) are not modeled.
