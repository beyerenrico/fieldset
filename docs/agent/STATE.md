# State: 2026-10-23T12:00Z
Milestone: M8 Hardening → 1.0 · gate passed on m8-hardening; merging to main, tag m8-done
M8 complete except T09 (release 1.0). Version 1.0.0 already bumped (24d363b); CHANGELOG and release
notes carry performance and gate numbers; ADR 0046 records the performance exceptions.
Gate: PHP 4652, coverage 95.11 %, PHPStan 0/0, Rector/Pint clean, JS 611/111/623/246, conformance
391/392, licenses/audits clean (composer audit in the CI image, LEARNINGS #72), E2E 75/75 ×2.
Last commit: see `git log -1`

## Next actions
1. Merge m8-hardening → main (--no-ff), tag m8-done.
2. T09: final full gate on main (coverage.sh, PHPStan x2, Rector, Pint, JS, conformance, audits, E2E),
   tag v1.0.0, docs/agent/FINAL_REPORT.md, tick T09, STATE "Run complete".

## Known problems
- Performance exceptions (ADR 0046, QUESTIONS #21): classic builder page 1.05 s, Field App first load
  under devtools throttling 3.5 s, 501st repeater row 203 ms.
- E2E: `npm run e2e -- --workers=2` with `COMPOSE_PROFILES=sso` (Keycloak); `php artisan cache:clear` in
  the app container between quick reruns.
- E2E runs rewrite evidence PNGs of earlier milestones: `git checkout` them before committing.
- Host Composer 2.8.12 fails `composer audit` on the ignored abandoned package; use the CI image.

## Resume commands
COMPOSE_PROFILES=sso docker compose up -d --wait
DB_DATABASE=form_schema_test php artisan test --compact --parallel
npm run test
vendor/bin/rector --dry-run --no-progress-bar   # CI gate, must be 0 files
