# 0023: Bundled templates with a URN registry for lineage
- Status: accepted
- Date: 2026-10-16

## Decision
Templates live in `spec/templates/*.json` with `spec/templates/index.json` (key, version, category,
titles). Importing sets `source = { registry: "urn:form-schema-app:bundled-templates", template, version }`
(D12). Template element ids are stable (UUIDv5 of template + path), so a future update mechanism
(Appendix B6) can match elements across template versions. Ids are not regenerated on import, only on
duplicate/clone.

## Consequences
Two forms created from the same template share element ids; ids only need to be unique within one
definition, so this is harmless.
