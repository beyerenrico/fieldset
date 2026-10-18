# State: 2026-10-18T18:00Z
v2 run (BUILD_PROMPT_V2.md, plan `docs/plans/2026-10-17-001-feat-docker-distribution-v2-plan.md`).
M9 and M10 done and merged to `main` (tags `m9-done`, `v2.0.0-alpha.1`, `m10-done`, `v2.0.0-alpha.2`; local
only, never pushed). Reports: `docs/agent/milestones/M9.md`, `M10.md`. Next branch: `m11-mail-phones`.

## Next actions
Branch `m11-mail-phones`: M11-T01 U12 and M11-T02 U13 are merged and ticked.
1. M11-T03 U14 phone HTTPS is IN PROGRESS in the agent worktree
   `.claude/worktrees/agent-a4b6c5fbdcb5fb6e1` (branch `worktree-agent-a4b6c5fbdcb5fb6e1`, WIP commit 1692924,
   based on m11-mail-phones before the U12 merge). The agent stopped on a usage limit while unifying LocalCaStore
   with LocalCaRoot and the RuntimeWriter constant. Resume it (or finish by hand): merge `m11-mail-phones` into
   that branch, finish local CA + tunnel + fake tunnel harness + J06, run tests and J06/J01/J02, then merge.
2. M11-T04 U15 E16 measurement (J10) after U14, with no other Docker work running (timings).
3. Gate M11-T05: also implement the §7 install `--smtp-*` flags (gap found in U12), J4–J6 + J10 + regressions on
   arm64 and amd64 emulated, E2E, reviewers, M11.md, build `2.0.0-beta.1`.
4. Pending ADR amendments from U12/U13 (and later U14) are listed in
   `docs/agent/milestones/evidence/M11/pending-adr-amendments.md`; apply them in one docs pass, then delete it.

## Decisions taken (M9/M10, see ADRs 0047–0079)
- Release tooling `release:manifest`/`release:version`; manifest and sign jobs split; stack file is a signed asset.
- Compose file is JSON; Valkey password + volume; cosign not in the image; Compose/age from source; scoped,
  dated Trivy exceptions for third-party images (QUESTIONS 27 open; expire 2026-11-17 / 2027-01-16).
- Runtime state drives URL/hosts/scheme/gate/tunnel policy; URL root keeps the request scheme behind a stale proxy.
- Edge: Caddy, fixed address outside Docker's pool, only trusted proxy; catcher behind forward_auth with
  edge-injected Mailpit Basic auth; acme_ca always set (LE prod default).
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
