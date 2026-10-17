# State: 2026-10-18T13:30Z
Milestone: M7 SSO + Builder v2 · Branch m7-sso-builder (M6 merged, tags m6-done + v0.2.0)
Done: M7 plan (d061673), ADR 0036 SSO + 0037 Builder v2, M7.md (ownership/interfaces), Builder v2
skeleton (58c0a78), SSO composer packages (3b3d09c).
All five M7 implementation agents landed (SSO A 3b65d5c, B 014aea3, C 1725f02; Builder BA 577df0e,
BB b93730d). Integration fixes: admin login step actions, SSRF guard pins getaddrinfo (libcurl
*.localhost). E2E: 25 old + 7 SSO/SAML pass; 4 Builder v2 journeys fail.
In flight: E2E fixer agent (Builder v2 journeys, exclusive Docker), three gate reviewers (read-only).
Last commit: see `git log -1`

## Next actions
1. E2E fixer result → commit; reviews → fix batch (disjoint agents), commit.
2. Gate M7: coverage, PHPStan, Rector, Pint, JS (4 workspaces), conformance, E2E ×2 with
   COMPOSE_PROFILES=sso and --workers=2, M7.md gate report, merge, tag m7-done; branch m8; break down M8.

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
