# State: 2026-10-16T15:30Z
Milestone: M1 Schema core + Builder v1 · Task: M1-T01..T09 (spec + both SDKs)
Status: spec + conformance committed (162113c). Two subagents are implementing form-schema-php and
form-schema-js in parallel. Forms domain, actions, BuilderMapper, Filament builder pages, templates
and their tests are written (wip 9a50b5a) but untested until the PHP SDK exists.
Branch: m1-schema-builder · Last commit: see `git log -1`

## Next actions
1. When the SDK agents report: review their contradictions list, run `npm run conformance`,
   PHPStan level 10 on the package, JS lint/typecheck/coverage.
2. Run the M1 Laravel tests (tests/Feature/Forms, tests/Unit/Forms) and fix.
3. Rebuild Docker, run Playwright `tests/e2e/m1-builder.spec.ts`, M1 gate with reviewer subagents.

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
