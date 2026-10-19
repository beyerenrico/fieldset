# State: 2026-10-19T23:30Z
v2 run (BUILD_PROMPT_V2.md, plan `docs/plans/2026-10-17-001-feat-docker-distribution-v2-plan.md`).
M9, M10 and M11 done and merged to `main` (tags `m9-done`, `v2.0.0-alpha.1`, `m10-done`, `v2.0.0-alpha.2`,
`m11-done`, `v2.0.0-beta.1`; local only, never pushed). Reports: `docs/agent/milestones/M9.md`, `M10.md`, `M11.md`.

## Next actions
1. Branch `m12-lifecycle`. M12-T01 U16 done. M12-T02 U17 merged (be7b56b; suite 6781/6781, static clean, J08
   12/12, J01 5/5, J12 3/3) but a verification review found 1 high + 2 medium + 4 low: second fix round by the U17
   agent in its worktree (`.claude/worktrees/agent-aee571cc145616b6a`, keep until merged), then merge and tick T02.
   T04 U19 / T05 U23 run in parallel worktrees now; T03 U18 starts after the U17 fixes merge (it reuses the restore
   swap). Briefs `m12-u18/u19/u23-brief.md` in the session scratchpad; DBs form_schema_u18/u19/u23, images
   fsa-test-u18/u19/u23, ADRs 0086/0087/0088. Merge sequentially, then the gate T06.
   Gate note: coverage in the CI image needs `--env-file` with the dev `.env` (LEARNINGS 140).
2. Backup service peak 68 MiB (J08, demo data) fits the 128 MiB slot: no budget change. M13 breakdown: admin-guide
   capture banner (U21); R39 operator notification for failed/stale backups (U20); R39 release check
   `pg_dump` major ≥ manifest Postgres major (U22); backup memory at the 1.0 data volume (J10).
3. Housekeeping blocked by the permission classifier: dropping stale test DBs in the dev Postgres
   (form_schema_m10b, u12, u12cov, u13, u14, u14b, u15, test_bb/d/e/m9/z and their `_test_N`); left for the human.
4. E2E: run with `--workers=2` when the host is busy (M11.md); leave ≥ 60 s between runs (LEARNINGS 128).

## Decisions taken (M9/M10, see ADRs 0047–0079)
- Release tooling `release:manifest`/`release:version`; manifest and sign jobs split; stack file is a signed asset.
- Compose file is JSON; Valkey password + volume; cosign not in the image; Compose/age from source; scoped,
  dated Trivy exceptions for third-party images (QUESTIONS 27 open; expire 2026-11-17 / 2027-01-16).
- Runtime state drives URL/hosts/scheme/gate/tunnel policy; URL root keeps the request scheme behind a stale proxy.
- Edge: Caddy, fixed address outside Docker's pool, only trusted proxy; catcher behind forward_auth with
  edge-injected Mailpit Basic auth; acme_ca always set (LE prod default).
- cloudflared is not a trusted proxy (ADR 0082); the app serves the local-CA trust page over plain HTTP too.
- `fsa config set` reverts on failure with exit 5 `rolled_back`; queue/scheduler stay stopped until verified.
- Journeys write evidence to `docs/agent/milestones/evidence/$FSA_EVIDENCE_MILESTONE` (default M9): set
  `FSA_EVIDENCE_MILESTONE=M12` for M12 runs.

## Known problems
- Trivy exceptions for gotenberg/caddy/cloudflared expire 2026-11-17: refresh pins or entries before a release.
- E2E runs rewrite evidence PNGs: `git checkout docs/agent/milestones/evidence` before committing.
- Long PHP runs need their own database (`form_schema_cov`); parallel agents use their own DBs.
- Outbound Docker Hub pulls were flaky at times (retry).

## Resume commands
COMPOSE_PROFILES=sso docker compose up -d --wait
DB_DATABASE=form_schema_test php artisan test --compact --parallel
FSA_EVIDENCE_MILESTONE=M12 vendor/bin/pest --testsuite=Install            # journeys (real Docker, fsa-test-*)
FSA_TEST_PLATFORM=linux/amd64 FSA_HARNESS_PLATFORMS=linux/amd64 vendor/bin/pest --testsuite=Install --filter=J01
npm run e2e -- --reporter=line
vendor/bin/rector --dry-run --no-progress-bar   # CI gate, must be 0 files
