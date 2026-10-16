# State: 2026-10-16T21:00Z
Milestone: M2 Field App online · Tasks in flight: M2-T18 gate (review fixes)
Status: all M2 features committed (API, PDF import, Scramble, renderer, Field App, preview, E2E journeys).
Gate checks run once: PHP 2986 tests, coverage 94.65 % (SDK 99.69, Schema/Tenancy 100), PHPStan 0/0,
JS 606 + 93 + 122 tests, conformance 391, E2E 9/9. Three reviews done (security, backend correctness,
frontend pending at time of writing). Device-session revocation fixed (0925fa3). A subagent fixes the
remaining backend findings (idempotency, numbering, draft retries, attachments, manifest, reprojection)
on DB form_schema_test_b and leaves changes uncommitted.
Branch: m2-field-app · Last commit: see `git log -1`

## Next actions
1. Review + commit the backend fix batch (stage explicit paths only); fix frontend review findings.
2. Re-run gate: `./scripts/coverage.sh`, both PHPStan configs, `npm run typecheck lint test build`,
   `npm run conformance`, rebuild Docker, `migrate:fresh --seed` + `cache:clear`, `npm run e2e`.
3. Write docs/agent/milestones/M2.md, tick M2-T12..T15, T17, T18, JOURNAL; merge --no-ff to main, tag
   m2-done; branch m3-documents; start M3 (PLAN M3-T01..T12).

## Known problems
- Builder page is heavy (~4 MB HTML for the service report) — QUESTIONS #8.
- Preview iframe same-origin (QUESTIONS #10, M8 task).
- E2E: Filament login throttle per IP — clear the cache between quick re-runs (LEARNINGS #25).

## Resume commands
docker compose up -d postgres redis gotenberg mailpit app queue scheduler
DB_DATABASE=form_schema_test_c php artisan test --compact
npm run conformance
