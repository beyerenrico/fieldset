# State: 2026-10-21 — Run complete
v2 run (BUILD_PROMPT_V2.md, plan `docs/plans/2026-10-17-001-feat-docker-distribution-v2-plan.md`) is complete.
M9–M13 done and merged to `main`; 2.0.0 released locally: tag `v2.0.0` on the release-2.0.0 merge (local only,
never pushed, nothing published). Tags: `m9-done` … `m13-done`, `v2.0.0-alpha.1`, `-alpha.2`, `-beta.1`, `-rc.1`, `v2.0.0`.
Final report: `docs/agent/FINAL_REPORT_V2.md`; gate reports `docs/agent/milestones/M9.md`–`M13.md`.

## Next actions
1. None for the agent. Human steps before a public release: FINAL_REPORT_V2.md "Human checklist" (GHCR first publish,
   QUESTIONS 25/27/41/74/76, Trivy exceptions expire 2026-11-17 / 2026-11-20).
2. Offered next: plan the GitHub Pages docs site (`/ce-plan`).
3. Test DBs form_schema_ga/gb/gc (+_test_N) and older ones may remain in the dev Postgres: left for the human.

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
- Trivy exceptions expire 2026-11-17 (gotenberg/caddy/cloudflared) and 2026-11-20 (cloudflared CVE-2026-63209).
- E2E runs rewrite evidence PNGs: revert only PNGs (LEARNINGS 159); commit journey evidence before E2E.
- Long PHP runs need their own database (`form_schema_cov`); parallel agents use their own DBs.
- Outbound Docker Hub pulls were flaky at times (retry).

## Resume commands
COMPOSE_PROFILES=sso docker compose up -d --wait
DB_DATABASE=form_schema_test php artisan test --compact --parallel
FSA_EVIDENCE_MILESTONE=M12 vendor/bin/pest --testsuite=Install            # journeys (real Docker, fsa-test-*)
FSA_TEST_PLATFORM=linux/amd64 FSA_HARNESS_PLATFORMS=linux/amd64 vendor/bin/pest --testsuite=Install --filter=J01
npm run e2e -- --reporter=line
vendor/bin/rector --dry-run --no-progress-bar   # CI gate, must be 0 files

## Rename to Fieldset (2026-10-21, branch `rename-fieldset`, plan `docs/plans/2026-10-21-001-refactor-rename-to-fieldset-plan.md`)
- U6 switched the dev stack to the Compose project `fieldset` (reset steps: LEARNINGS 163). The stopped project
  `form-schema-app` keeps its volumes and images for the human to remove. Suite DB: `DB_DATABASE=fieldset_test`.
