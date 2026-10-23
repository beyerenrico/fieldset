# Changelog — Fieldset Spec 1.0

## Editorial — 2026-10-22 (no version change)
- The README states the meta-schema's SHA-256 and advises validators to bundle `meta-schema.json` rather than fetch
  its `$id` at runtime; the docs site serves the file at the `$id` URL. No semantic change: `specVersion` stays
  `1.0.0` and the meta-schema is unchanged.

## Rename — 2026-10-21 (no version change)
- Renamed from "Form Schema Spec 1.0" to "Fieldset Spec 1.0". The meta-schema `$id` is now
  `https://beyerenrico.github.io/fieldset/spec/form-definition/1.0/meta-schema.json` (was
  `https://localhost/spec/form-definition/1.0/meta-schema.json`); the bundled template registry is
  `urn:fieldset:bundled-templates`. No semantic change: `specVersion` stays `1.0.0`, `$schema` and
  `source.registry` remain pattern-only, so definitions naming the former URIs stay valid.

## 1.0.0 — 2026-10-16
- Initial release: definition structure, 30 element types, JsonLogic dialect with decimal and date
  operators, evaluation algorithm, validation error codes, lint rules, versioning and checksum rules.
- Reserved for later features: `role` (with `roles.json`), `source`, `i18n`, the `record` type.
- Documented extension: `x-import`.
- Clarified (same day, before any release): round stability, incremental visibility, normalization
  errors, repeater `minItems` on empty lists, rating/attachment/signature/location/hidden checks,
  JS `Number()` and `==` array rules, variadic `min`/`max`, operator error handling, lint path and
  reference rules, data-contract `time` pattern.
- Hardened (M1 review, before release): restricted ECMAScript pattern subset with fixed semantics and
  no nested quantifiers (§3.4), 4,096-code-point pattern subject limit, 60-digit decimal limit,
  512-level nesting limit and `logic_too_deep`, calculation order (topological, document order for
  ties), 254-code-point email limit, finite location numbers, explicit check order, meta-schema URI
  pattern instead of `format: uri`, strict asset UUIDs.
