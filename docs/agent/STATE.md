# State: 2026-10-18T07:45Z
Milestone: M7 SSO + Builder v2 · Branch m7-sso-builder (M6 merged, tags m6-done + v0.2.0)
Tasks in flight: two design agents (SSO → ADR 0036 draft, Builder v2 → ADR 0037 draft); M7 broken
down in PLAN (d061673).
Last commit: see `git log -1`

## Next actions
1. Design results → write ADR 0036 (SSO) and 0037 (Builder v2); install packages; create the shared
   skeleton (migrations/models/interfaces, routes, config) the designs ask for.
2. Dispatch implementation agents with disjoint ownership (SSO backend, SSO UI/Field App/Keycloak,
   Builder v2 server, Builder v2 client); E2E journeys; reviews; gate M7.

## Known problems
- Builder v1 page heavy (QUESTIONS #8): E2E waits 30 s for its navigation/publish.
- Preview iframe same-origin (QUESTIONS #10, M8) — now also guards the Field App's IndexedDB.
- E2E: `npm run e2e` (browsers in storage/playwright); `php artisan cache:clear` in the app container
  between quick reruns; use `--workers=2` when the machine is loaded (antivirus spikes).
- E2E runs rewrite evidence PNGs of earlier milestones: `git checkout` them before committing.
- Field App tests that touch Dexie with fake timers must use FAKE_TIMERS (LEARNINGS #50).

## Resume commands
docker compose up -d
DB_DATABASE=form_schema_test php artisan test --compact --parallel
npm run test -w resources/field-app
vendor/bin/rector --dry-run --no-progress-bar   # CI gate, must be 0 files
