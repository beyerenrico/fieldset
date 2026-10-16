# 0013: D11 Semantic field roles in spec 1.0
- Status: accepted
- Date: 2026-10-16

## Decision
Optional `role` string per element, vocabulary in `spec/form-definition/1.0/roles.json` (starter
list from §7.7). Linter: unknown role = warning; duplicate role in one scope = error (repeater rows
are separate scopes). Webhook payloads and exports include roles. Invoice and service-report
templates set roles.

## Consequences
Integrations can map by role across forms. Vocabulary changes are additive.
