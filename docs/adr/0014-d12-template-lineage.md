# 0014: D12 Template lineage via `source`
- Status: accepted
- Date: 2026-10-16

## Decision
Definitions may carry `source: {registry, template, version}`. Set on template import, kept on
clone. No update propagation in v1 (Appendix B6).

## Consequences
Cheap field now; enables upstream update notices later.
