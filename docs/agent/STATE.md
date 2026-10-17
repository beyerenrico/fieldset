# State: 2026-10-17T00:00Z
v2 run started (BUILD_PROMPT_V2.md, plan `docs/plans/2026-10-17-001-feat-docker-distribution-v2-plan.md`).
Branch `m9-release` from `main` (v1.0.0 + fixes). Bootstrap M9-T01 in progress: ADRs for E1–E18 next.

## Next actions
1. M9-T01: ADRs 0047+ for E1–E18 (versions from BUILD_PROMPT_V2 §5 / Appendix A, checked 2026-10-17).
2. M9-T02 (U1 image) and M9-T03 (U2 instance domain core) — independent, run in parallel.
3. Then M9-T04 (U3), M9-T05 (U6), M9-T06 (U7), M9-T07 (U4), M9-T08 (U5), gate M9-T09.

## Decisions taken at bootstrap
- U7 is verified by building, signing and serving the harness release; the install through J1 belongs to
  U5 (resolves the plan's deferred U5/U7 cycle).

## Known problems
- E2E runs rewrite evidence PNGs: `git checkout docs/agent/milestones/evidence` before committing.
- Host Composer 2.8.12 fails `composer audit` on the ignored abandoned package; use the CI image.

## Resume commands
docker compose up -d --wait
DB_DATABASE=form_schema_test php artisan test --compact --parallel
npm run test
vendor/bin/rector --dry-run --no-progress-bar   # CI gate, must be 0 files
