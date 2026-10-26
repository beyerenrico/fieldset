# State: 2026-10-26 — 2.1.1 WSL parity implemented on `maint-wsl-parity` (PR #1); 2.1.0 is the published stable
v2 run complete (2.0.0, local tag `v2.0.0`, never published; `docs/agent/FINAL_REPORT_V2.md`).
Rename to Fieldset (human decision, QUESTIONS 1/25; plans and brainstorms are local only, QUESTIONS 79;
ADR 0092): U1–U8 done; Trivy fix ADR 0093 (QUESTIONS 77a). Published tags, each human-approved and pushed by name:
`v2.1.0-rc.1` to `rc.4` and `v2.1.0` (2c224db8). Local only: `v2.0.0`, `rename-done`, `mX-done`.
Gate report `docs/agent/milestones/R2.1.md`, evidence `milestones/evidence/R2.1/`.

Repo `beyerenrico/fieldset`; the agent may push branches and main to get CI green, never tags without approval, never force
(human: "you are allowed to push … make sure that the CI is green"). The 2.1.0 CI fixes and human decisions are in JOURNAL 2026-10-21..23 and QUESTIONS 77–80.

## Next actions
00. 2.1.1 WSL parity (plan `docs/plans/2026-10-26-001-feat-wsl-parity-and-query-tuning-plan.md`, local only; ADR 0095):
   branch `maint-wsl-parity`, PR https://github.com/beyerenrico/fieldset/pull/1 (LFG run: U1–U8, U14, U15, simplify, review).
   Seven validated review residuals (1 P1, 6 P2) in `docs/residual-review-findings/maint-wsl-parity.md`: fix before merge.
   Human steps: run `scripts/wsl/facts.sh` on Windows (QUESTIONS 81), approve `v2.1.1-rc.1`, run the RELEASING WSL
   checklist. Phase 2 (2.1.2 query tuning, plan U9–U13) follows on its own branch.
0. Nightly `Install journeys` runs (02:17 UTC): fix a red one before anything else.
1. `v2.1.0` (2c224db8, human: "Go ahead") published as stable and Latest: Release run 39970942791 green; checks from
   outside CI (cosign, SHA256SUMS, anonymous pulls, tags 2.1.0/2.1/2/latest, update feed, docs) in
   `evidence/R2.1/postpublish-v2.1.0.txt`. rc.4 stays a pre-release, rc.1 a draft (QUESTIONS 77e); rc.2 and rc.3 have
   no release. Remaining post-publish checks need real accounts/devices (RELEASING). Next tag needs human approval.
2. Trivy exceptions expire 2026-11-17 (older gotenberg, caddy), 2026-11-20 (CVE-2026-111943: postgres, gotenberg,
   mailpit, caddy; cloudflared CVE-2026-63209) and 2026-11-22 (60 Chromium 156 fixes in gotenberg 8.38.1): refresh pins
   when upstream images ship (Go >= 1.26.10 / 1.27.3, Chromium 156) or renew them in a reviewed commit.
3. QUESTIONS 77b (PDF fixture producer) open. Docs site live (ADR 0094; Docs run 39994845764, docs-site 0cc99c88).
   Open human choices: Wiki off, About website (QUESTIONS 78 e/f).
4. Next patch 2.1.x: query tuning (QUESTIONS 41/74/80): saved reports, explorer search, inbox, API in_review series.

## Gate results (R2.1.md, rerun after the Go fix)
- Static, licenses clean; PHP 7404/7404; Vitest 615/111/622/246; conformance 391/392.
- Install journeys (2.1.0-alpha.1-test) 151 passed / 7 skipped, dogfood 71; 2.1.0 multi-arch build 7/7;
  J1 amd64 5/5; J1 + J7 at 2.1.0 13/13.
- Trivy green: app arm64 + amd64 without exceptions; every third-party image both platforms with its own file.
- E2E dev stack 79 passed / 8 skipped (run 2; run 1 had the known m6-offline click flake).

## Pins now (ADR 0093)
- golang 1.27.3-bookworm, FrankenPHP 1-php8.4-bookworm (built with Go 1.27.3), Docker CLI 29.9.2 (Go 1.26.10).
- cloudflared 2026.10.2; postgres 16.15-alpine, gotenberg 8.38.1, mailpit v1.31.6, caddy 2.11.7, valkey 8.1.10 unchanged.

## Decisions taken
- Install journeys: `.github/workflows/install-journeys.yml` (nightly 02:17 UTC, workflow_dispatch, workflow_call),
  not in `ci.yml`; `release.yml` job `journeys` gates `image` (a release takes about two hours longer).
- No version file for 2.1.0: `release:version` takes the version as an argument, `release.json` has none.
- Journeys write release evidence to `R<major>.<minor>` (`FIELDSET_EVIDENCE_MILESTONE=R2.1`); M10-pinned journeys
  still write into M10: copy to `R2.1/M10-pinned/` and restore M10 (R6). Emulated and `FIELDSET_RELEASE_VERSION` runs
  overwrite the native J01/J07 files: copy them to `J01-amd64-*` / `J01-v2.1.0-*`, `J07-v2.1.0-*`, restore the natives.

## Known problems
- E2E runs rewrite evidence PNGs: revert only PNGs (LEARNINGS 159). m6-offline click flake under load (QUESTIONS 76c).
- The harness build fetches fonts from the network (Vite fonts plugin): a CDN timeout fails the build (LEARNINGS 166).

## Resume commands
COMPOSE_PROFILES=sso docker compose up -d --wait
DB_DATABASE=fieldset_test php artisan test --compact --parallel
FIELDSET_EVIDENCE_MILESTONE=R2.1 FIELDSET_REQUIRE_DOCKER=1 vendor/bin/pest --testsuite=Install
npm run e2e -- --reporter=line --workers=2
vendor/bin/rector --dry-run --no-progress-bar   # must be 0 files
