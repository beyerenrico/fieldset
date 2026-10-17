# State: 2026-10-18T19:00Z
Milestone: M7 SSO + Builder v2 · Branch m7-sso-builder (M6 merged, tags m6-done + v0.2.0)
Done: M7 plan (d061673), ADR 0036 SSO + 0037 Builder v2, M7.md (ownership/interfaces), Builder v2
skeleton (58c0a78), SSO composer packages (3b3d09c).
M7 implementation + integration committed. Reviews done; fixes committed: SSO (c1b0c5a: Owner-only
IdP security, front-channel spoofing, SAML subject, login CSRF), Field App login (67b582e), Builder v2
E2E fixes (4a21074), production guard (c76cfc9).
In flight: FB Builder v2 fix agent (review findings: a11y keyboard/focus, audit on save, publish race).
Last commit: see `git log -1`

## Next actions
1. FB result → commit; rebuild (COMPOSE_PROFILES=sso), reseed, full E2E ×2 (--workers=2).
2. Gate M7: coverage.sh, PHPStan, Rector, Pint, JS (4 workspaces), conformance, licenses; M7.md;
   merge, tag m7-done; branch m8-hardening; break down M8.

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
