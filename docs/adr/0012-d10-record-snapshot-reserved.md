# 0012: D10 `record` element reserved with snapshot semantics
- Status: accepted
- Date: 2026-10-16

## Context
§0 D10 / §7.7. Records ship after 1.0 (Appendix B1).

## Decision
Spec 1.0 documents `record` as reserved with snapshot semantics; the 1.0 meta-schema and the
linter reject `type: "record"`. No records tables in v1.

## Consequences
Enabling `record` later is additive (spec 1.1) and needs no data migration.
