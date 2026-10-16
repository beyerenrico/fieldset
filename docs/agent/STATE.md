# State: 2026-10-16T15:30Z
Milestone: M1 Schema core + Builder v1 · Task: M1-T01..T09 (spec + both SDKs)
Status: M0 merged to main (tag m0-done). Spec 1.0 README, meta-schema, roles.json and the
conformance suite (136 logic, 55 evaluate, 37 lint, 17 meta-schema, 5 checksum) are drafted.
Branch: m1-schema-builder · Last commit: see `git log -1`

## Next actions
1. Dispatch two subagents in parallel: form-schema-php (packages/form-schema-php) and form-schema-js
   (packages/form-schema-js), both implementing README §2–§10 against spec/conformance.
2. Meanwhile: M1-T10 forms/form_versions migrations+models (store definitions as JSON text-faithful
   jsonb, decode objects as stdClass via a custom cast), M1-T11 publish/rollback/retire actions.
3. Integrate SDK results, run `npm run conformance`, then Builder v1 (M1-T13..T17).

## Files in flight
- spec/form-definition/1.0/*, spec/conformance/** (commit at branch start)

## Known problems
- Empty JSON object vs array: PHP SDK must use json_decode(..., false) trees (stdClass) so `{}`
  survives (checksums, `{}` truthiness). Builder `_extra` is a JSON string for the same reason.

## Tried and rejected
- Wrapping json-logic-php/js (ADR 0021)

## Resume commands
docker compose up -d postgres redis gotenberg mailpit
php artisan test --compact
./scripts/coverage.sh        # coverage gate in docker/ci image
