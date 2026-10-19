# State: 2026-10-19T14:00Z
v2 run (BUILD_PROMPT_V2.md, plan `docs/plans/2026-10-17-001-feat-docker-distribution-v2-plan.md`).
M9 and M10 done and merged to `main` (tags `m9-done`, `v2.0.0-alpha.1`, `m10-done`, `v2.0.0-alpha.2`; local
only, never pushed). Reports: `docs/agent/milestones/M9.md`, `M10.md`. Next branch: `m11-mail-phones`.

## Next actions
Branch `m11-mail-phones`: M11-T01..T04 merged and ticked; gate M11-T05 IN PROGRESS. Review (5 reviewers, 4 high,
`evidence/M11/review-findings.md`) fixed by agents A–D and merged. Done and recorded in M11.md: static/JS/licence
gates, coverage (95.51 %, Instance 97.36 %), native Install suite 103/103 (+7 pre-release skips), amd64 emulated
J4/J5/J6/J1/J2/J3/J12 51/51, CHANGELOG 2.0.0-beta.1 (+ missing alpha.2 entry), M12 breakdown in PLAN.md.
1. J10 under amd64 emulation (ADR 0083 commitment) running → `storage/agent-logs/m11-j10-amd64.log`. Afterwards
   keep the native J10 evidence (`git checkout docs/agent/milestones/evidence/M11`), record the outcome in M11.md.
2. Rebuild the dev image (`docker compose build app queue scheduler`), E2E twice, `git checkout
   docs/agent/milestones/evidence` for PNG churn; record in M11.md.
3. `FSA_PRERELEASE_VERSION=2.0.0-beta.1 FSA_EVIDENCE_MILESTONE=M11 vendor/bin/pest --testsuite=Install
   --filter=LocalPreRelease`; record; commit evidence.
4. Merge `--no-ff` to main, tag `m11-done` and `v2.0.0-beta.1` (local only), tick M11-T05, JOURNAL, then M12-T01.
5. Housekeeping blocked by the permission classifier: dropping stale test DBs in the dev Postgres
   (form_schema_m10b, u12, u12cov, u13, u14, u14b, u15, test_bb/d/e/m9/z and their `_test_N`); left for the human.

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
