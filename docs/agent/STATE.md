# State: 2026-10-19T08:00Z
v2 run (BUILD_PROMPT_V2.md, plan `docs/plans/2026-10-17-001-feat-docker-distribution-v2-plan.md`).
M9 and M10 done and merged to `main` (tags `m9-done`, `v2.0.0-alpha.1`, `m10-done`, `v2.0.0-alpha.2`; local
only, never pushed). Reports: `docs/agent/milestones/M9.md`, `M10.md`. Next branch: `m11-mail-phones`.

## Next actions
Branch `m11-mail-phones`: M11-T01..T04 (U12, U13, U14, U15) are merged and ticked. U15: E16 met except admin
pages (QUESTIONS 41, ADR 0083); small budget retuned.
1. U15 ADR amendments are listed in `docs/agent/milestones/evidence/M11/pending-adr-amendments.md` (gate pass).
2. Done and merged: install `--smtp-*` flags + full R11 smoke test (PDF, test mail); J01–J06 native green.
3. Gate M11-T05 IN PROGRESS. Review done (5 reviewers): findings in `evidence/M11/review-findings.md` (4 high).
   Fix agents in worktrees: A tunnel journal/contract/evidence redaction, B demo identity + exposure on
   `config set domain`, C mail/config safety + small Postgres, D texts/docs + U15 ADR amendments. Merge each
   (`--no-ff`), then: full suite + coverage (form_schema_cov), all journeys native + J4/J5/J6/J10 + J1/J2/J3/J12
   amd64 emulated (recheck J6 port-80 reset), E2E ×2 after rebuilding the dev image, M11.md, CHANGELOG Unreleased →
   2.0.0-beta.1, LocalPreRelease beta.1, merge to main, tags. At M13 breakdown: admin-guide.md capture banner (U21).
4. Drop leftover test DBs when done: form_schema_u12, u12cov, u13, u14, u14b, u15, m10a, m10b
   (fix agents drop their own fixa–fixd).

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
