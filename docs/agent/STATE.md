# State: 2026-10-18T09:30Z
Milestone: M7 SSO + Builder v2 · Branch m7-sso-builder (M6 merged, tags m6-done + v0.2.0)
Done: M7 plan (d061673), ADR 0036 SSO + 0037 Builder v2, M7.md (ownership/interfaces), Builder v2
skeleton (58c0a78), SSO composer packages (3b3d09c).
Tasks in flight (3 agents, shared tree, nothing committed by them):
- SSO skeleton (migrations, models, enums, interfaces + stubs, routes, config, Keycloak compose, CI)
  — DB form_schema_test_c.
- BA Builder v2 server (CanvasBuilder, NodeMapper, SyncDraft, inspector, actions) — DB _b.
- BB Builder v2 client (resources/builder-v2 store/canvas/keyboard/sync, Blade, lang builder_v2).
Last commit: see `git log -1`

## Next actions
1. SSO skeleton → review, commit; dispatch SSO A (core), B (admin UI), C (Field App, Keycloak, E2E)
   per docs/agent/milestones/M7.md.
2. BA/BB → review, commit; BB writes Builder v2 E2E after BA; integration; reviews; gate M7.
3. Known flake: SearchPlanTest (planner) fails sometimes under --parallel; passes alone — check ANALYZE.

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
