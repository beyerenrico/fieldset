# State: 2026-10-19T06:00Z
Milestone: M8 Hardening → 1.0 · Branch m8-hardening (M7 merged, tag m7-done)
Phase 1 committed: audit/GDPR (fb9678a), integration tokens (57ff6fe), security/opaque preview/
/platform/env templates (b4dbb3a), guides + backup scripts (b1bc71d), reviewer version access (d60d658).
E2E 34/34 with the new CSP. PLAN: T01, T02, T03, T06, T07, T11, T12 done.
In flight: G3 (exclusive Docker): CSP-violation E2E guard, a11y audit (axe), carry-overs (reviewer E2E
assertion, anonymized PDF 410, builder v2 default ADR 0044, web push ADR 0045).
Last commit: see `git log -1`

## Next actions
1. After G3: performance/load evidence (T05, Docker-exclusive agent), then docs finalize (resolve
   TODO-VERIFY markers in docs/guide, upgrade notes for M8, CHANGELOG 1.0, release notes).
2. Three gate reviews; fixes; gate M8; release 1.0 (T09): final gate on main, FINAL_REPORT.md,
   STATE "Run complete".

## Known problems
- Phase 2 after G1/G2: a11y audit E2E (T04) incl. a reviewer-detail assertion in m4-review, builder
  v2 as default (QUESTIONS #8, ADR), performance evidence (T05).
- Builder v1 page heavy (QUESTIONS #8); v2 exists, v1 still default — decide in T08.
- E2E: `npm run e2e -- --workers=2` with `COMPOSE_PROFILES=sso` (Keycloak); `php artisan cache:clear` in
  the app container between quick reruns; recreate Keycloak after realm changes
  (`docker compose up -d --force-recreate keycloak`).
- E2E runs rewrite evidence PNGs of earlier milestones: `git checkout` them before committing.
- Field App tests touching Dexie with fake timers must use FAKE_TIMERS (LEARNINGS #50).

## Resume commands
COMPOSE_PROFILES=sso docker compose up -d --wait
DB_DATABASE=form_schema_test php artisan test --compact --parallel
npm run test
vendor/bin/rector --dry-run --no-progress-bar   # CI gate, must be 0 files
