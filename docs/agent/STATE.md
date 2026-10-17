# State: 2026-10-18T10:15Z
Milestone: M7 SSO + Builder v2 · Branch m7-sso-builder (M6 merged, tags m6-done + v0.2.0)
Done: M7 plan (d061673), ADR 0036 SSO + 0037 Builder v2, M7.md (ownership/interfaces), Builder v2
skeleton (58c0a78), SSO composer packages (3b3d09c).
Tasks in flight (5 agents, shared tree, nothing committed by them; ownership in milestones/M7.md):
- SSO A login core (providers, callback, JIT, gate, SAML) — DB form_schema_test_c.
- SSO B admin UI, domains, enforcement, notifications, email-first admin login — DB _d.
- SSO C Field App email-first login, Keycloak realm, SsoDemoSeeder, E2E specs (run later) — DB _e.
- BA Builder v2 server — DB _b. BB Builder v2 client.
Skeletons committed: SSO 7ed4412, Builder v2 58c0a78.
Last commit: see `git log -1`

## Next actions
1. Review + commit each hand-back (explicit paths); apply requested orchestrator-file changes.
2. Integration: Docker rebuild with COMPOSE_PROFILES=sso; full E2E incl. m7-sso/saml and builder v2.
3. Three reviews; fix batch; gate M7 (coverage, static, JS, conformance, E2E ×2); merge, tag m7-done.

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
