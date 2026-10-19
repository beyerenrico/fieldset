# State: 2026-10-19T17:30Z
v2 run (BUILD_PROMPT_V2.md, plan `docs/plans/2026-10-17-001-feat-docker-distribution-v2-plan.md`).
M9, M10 and M11 done and merged to `main` (tags `m9-done`, `v2.0.0-alpha.1`, `m10-done`, `v2.0.0-alpha.2`,
`m11-done`, `v2.0.0-beta.1`; local only, never pushed). Reports: `docs/agent/milestones/M9.md`, `M10.md`, `M11.md`.

## Next actions
1. Create branch `m12-lifecycle` from `main`. M12 is broken down in PLAN.md (T01 U16 first, then T02 U17, then
   T03/T04/T05 in parallel worktrees). Start M12-T01 U16 backups: re-read plan U16, KTD15–KTD17, §8 backups.
2. U16 must fit the backup service into its 128 MiB slot of the small budget and rerun J10 if the table changes
   (ADR 0083). At the M13 breakdown: admin-guide.md capture banner section (U21).
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
  `FSA_EVIDENCE_MILESTONE=M11` for M11 runs.

## Known problems
- Trivy exceptions for gotenberg/caddy/cloudflared expire 2026-11-17: refresh pins or entries before a release.
- E2E runs rewrite evidence PNGs: `git checkout docs/agent/milestones/evidence` before committing.
- Long PHP runs need their own database (`form_schema_cov`); parallel agents use their own DBs.
- Outbound Docker Hub pulls were flaky at times (retry).

## Resume commands
COMPOSE_PROFILES=sso docker compose up -d --wait
DB_DATABASE=form_schema_test php artisan test --compact --parallel
FSA_EVIDENCE_MILESTONE=M11 vendor/bin/pest --testsuite=Install            # journeys (real Docker, fsa-test-*)
FSA_TEST_PLATFORM=linux/amd64 FSA_HARNESS_PLATFORMS=linux/amd64 vendor/bin/pest --testsuite=Install --filter=J01
npm run e2e -- --reporter=line
vendor/bin/rector --dry-run --no-progress-bar   # CI gate, must be 0 files
