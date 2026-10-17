# State: 2026-10-19T14:00Z
Milestone: M8 Hardening → 1.0 · Branch m8-hardening (M7 merged, tag m7-done)
Phase 1+2 committed (audit/GDPR, tokens, security, /platform, a11y/CSP E2E, builder default, docs 1.0).
Gate reviews done (security ASVS L2, correctness, UX/i18n); fix batch committed: privacy 3063dda,
erased names 6be2e61, audit 3273672, platform/security b6ce51a, follow-ups 47cd12d, proxies c27c56f,
password_known column (in 3063dda), notifications index c23befd.
In flight: G6 perf/load (exclusive Docker; uncommitted Reports/Submissions/QueryPlan/form-schema-js files
and scripts/perf/ are its WIP).
Last commit: see `git log -1`

## Next actions
1. Collect G6/G7 and the reviews; fix batch; full gate (coverage.sh, PHPStan x2, Rector, Pint, JS x4,
   conformance, licenses/audits, E2E x2 with COMPOSE_PROFILES=sso --workers=2); M8.md; merge, tag m8-done.
2. Release 1.0 (T09): version bump, CHANGELOG/release notes perf numbers, final gate on main,
   FINAL_REPORT.md, STATE "Run complete".

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
