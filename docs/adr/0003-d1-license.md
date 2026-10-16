# 0003: D1 License — AGPL-3.0 app, MIT spec and SDKs
- Status: accepted
- Date: 2026-10-16

## Context
§0 D1 default. AGPL deters closed hosted clones; an MIT spec encourages adoption.

## Decision
Root `LICENSE` = AGPL-3.0-only. `spec/`, `packages/form-schema-php`, `packages/form-schema-js`
each carry an MIT `LICENSE`. Every dependency must be OSI-approved and AGPL-compatible
(checked in CI).

## Consequences
Contributors need to accept the split; a CLA/DCO is not decided (QUESTIONS.md).
