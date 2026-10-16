# 0015: D13 Optional `i18n` label maps in spec 1.0
- Status: accepted
- Date: 2026-10-16

## Decision
`i18n` maps (`{ "<bcp47>": "text" }`) are allowed for `label`, `help`, `placeholder` and option
labels, as `i18n: { label: {...}, help: {...}, placeholder: {...} }` on elements and options.
v1 builder, renderer and PDFs use only the base `locale`. Stored and round-tripped losslessly.

## Consequences
Multilingual filling (B7) can arrive without a breaking spec change.
