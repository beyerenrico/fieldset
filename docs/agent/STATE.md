# State: 2026-10-23 — Fieldset 2.1.0 published (stable, Latest); docs site serves the final 2.1 copy
v2 run complete (2.0.0, local tag `v2.0.0`, never published; `docs/agent/FINAL_REPORT_V2.md`).
Rename to Fieldset (human decision, QUESTIONS 1/25; plans and brainstorms are local only, QUESTIONS 79;
ADR 0092): U1–U8 done; Trivy fix ADR 0093 (QUESTIONS 77a). Published tags, each human-approved and pushed by name:
`v2.1.0-rc.1` to `rc.4` and `v2.1.0` (2c224db8). Local only: `v2.0.0`, `rename-done`, `mX-done`.
Gate report `docs/agent/milestones/R2.1.md`, evidence `milestones/evidence/R2.1/`.

GitHub repo renamed to `beyerenrico/fieldset` by the human; they pushed `main` (ff5646ba), then allowed the agent to
push to get CI green ("you are allowed to push … make sure that the CI is green"; never tags, never force). Fixes on
main: `ci-services` (d362fc1f), `ci-e2e-scheduler` (963c07c0), `ci-classic-builder-lock` (c82c31f0: ClassicBuilderSlot,
one classic-builder render at a time, ADR 0083 amendment; Dogfood records container deaths and a timeline) and
`ci-nightly-journeys` (89abe7e2: human decision "Nightly + release", ADR 0091 amendment; sync backoff test and dev
compose start-order flakes). Both branches green on GitHub before the merge (runs 39715804524 with journeys 148 passed /
7 skipped / 3 warnings, and 39728456302). `fix-shell-session-race`: `/app/shell` without session middleware (ADR 0035
amendment; CI run 39747027552 lost the SSO start token). Human decisions 2026-10-22: move `v2.1.0` to a green main
commit ("Move to main"); trademark pre-check done, "Go ahead" with Fieldset; old dev resources deleted (77d).

## Next actions
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
