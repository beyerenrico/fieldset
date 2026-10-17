# State: 2026-10-18T07:45Z
Milestone: M7 SSO + Builder v2 · Branch m7-sso-builder (M6 merged, tags m6-done + v0.2.0)
Tasks in flight: none. M7 to be broken down in PLAN (first action).
Last commit: see `git log -1`

## Next actions
1. Break down M7 in PLAN (BUILD_PROMPT §8.7 SSO, §8.2 Builder v2, §13 M7): OIDC per org (Socialite +
   filament-socialite), domain verification (DNS TXT with a fake resolver), home-realm discovery, JIT +
   claim mapping, enforced SSO + break-glass owner, Keycloak in Docker/CI, SAML (socialiteproviders/
   saml2 4.10); Builder v2 page (palette/canvas/inspector, SortableJS, keyboard moves, undo/redo).
2. ADRs: SSO architecture + versions (verify on Packagist), Builder v2 architecture.
3. Dispatch parallel agents (SSO backend, Builder v2 UI) with disjoint ownership; E2E journeys; gate.

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
