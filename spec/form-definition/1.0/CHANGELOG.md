# Changelog — Form Schema Spec 1.0

## 1.0.0 — 2026-10-16
- Initial release: definition structure, 30 element types, JsonLogic dialect with decimal and date
  operators, evaluation algorithm, validation error codes, lint rules, versioning and checksum rules.
- Reserved for later features: `role` (with `roles.json`), `source`, `i18n`, the `record` type.
- Documented extension: `x-import`.
- Clarified (same day, before any release): round stability, incremental visibility, normalization
  errors, repeater `minItems` on empty lists, rating/attachment/signature/location/hidden checks,
  JS `Number()` and `==` array rules, variadic `min`/`max`, operator error handling, lint path and
  reference rules, data-contract `time` pattern.
