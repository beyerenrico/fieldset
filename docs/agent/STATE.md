# State: 2026-10-18T21:00Z
Milestone: M8 Hardening → 1.0 · Branch m8-hardening (M7 merged, tag m7-done)
Tasks in flight (3 agents, shared tree): G1 audit UI + GDPR (DB _b, ADRs 0038/0039), G2 security
headers + opaque preview + /platform (DB _c, ADRs 0040/0041), G4 docs/guides + backup scripts (ADR 0042).
Done: reviewer version access fix (d60d658, M8-T08 part).
Last commit: see `git log -1`

## Next actions
1. M8 parallel work with disjoint ownership (one ADR per architectural choice):
   - audit UI + retention + trigger/hash chain (T01) and GDPR retention/export/erasure (T02);
   - security hardening incl. opaque preview origin (T03) and /platform panel (T06);
   - a11y audit (T04), performance/load evidence (T05), docs/guides (T07), carry-overs (T08).
2. Gate M8, then release 1.0 (T09): final gate on main, FINAL_REPORT.md, STATE "Run complete".

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
