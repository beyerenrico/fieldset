# State: 2026-10-18T12:00Z
v2 run (BUILD_PROMPT_V2.md, plan `docs/plans/2026-10-17-001-feat-docker-distribution-v2-plan.md`).
M9 done: merged to `main`, tags `m9-done` and `v2.0.0-alpha.1` (local only, never pushed). Report:
`docs/agent/milestones/M9.md`. Next branch: `m10-modes` from `main`. Last commit: `git log -1`.

## Next actions
1. Create branch `m10-modes` from `main`.
2. M10-T01 U10 runtime state in the app (first; edge/proxy modes depend on it).
3. M10-T02 U8 edge + domain mode and M10-T03 U9 proxy mode + stack file, in parallel worktrees (both extend
   ComposeRenderer: expect a merge in `Compose/`). Verify Mailpit's UI behind Caddy `forward_auth` first.
4. M10-T04 U11 `fsa config`, then gate M10-T05 (J2, J3 + J1/J12 regression on arm64 and amd64 emulated).

## Decisions taken (M9)
- U7 verified by building/signing/serving the harness release; J1 belongs to U5.
- Release tooling commands are `release:manifest` / `release:version` (ADR 0067); manifest and sign jobs split.
- Compose file is JSON; Valkey password + volume (ADR 0069). cosign not in the image; Compose/age from source;
  third-party Trivy exceptions scoped and dated (ADR 0071, QUESTIONS 27 open; expire 2026-11-17 / 2027-01-16).
- Uninstall `--keep-backups` writes a final backup first (ADR 0073). Memory limits double under emulation.

## Known problems
- Trivy exceptions for gotenberg/caddy/cloudflared expire 2026-11-17: refresh pins or entries before a release.
- `SearchPlanTest` "rare word" can pick a seq scan on a loaded host (passed in all gate runs); watch it.
- E2E runs rewrite evidence PNGs: `git checkout docs/agent/milestones/evidence` before committing.
- Host Composer 2.8.12 fails `composer audit` on the ignored abandoned package; use the CI image.
- Long PHP runs need their own database (`form_schema_cov` for coverage) when agents run tests in parallel.

## Resume commands
COMPOSE_PROFILES=sso docker compose up -d --wait
DB_DATABASE=form_schema_test php artisan test --compact --parallel
vendor/bin/pest --testsuite=Install                       # journeys (real Docker, fsa-test-*)
FSA_TEST_PLATFORM=linux/amd64 FSA_HARNESS_PLATFORMS=linux/amd64 vendor/bin/pest --testsuite=Install --filter=J01
npm run e2e -- --reporter=line
vendor/bin/rector --dry-run --no-progress-bar   # CI gate, must be 0 files
