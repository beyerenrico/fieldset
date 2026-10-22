# State: 2026-10-22 — Fieldset 2.1.0 cut locally, CI green on GitHub, install journeys nightly + release gate
v2 run complete (2.0.0, local tag `v2.0.0`, never published; `docs/agent/FINAL_REPORT_V2.md`).
Rename to Fieldset (human decision, QUESTIONS 1/25; plan `docs/plans/2026-10-21-001-refactor-rename-to-fieldset-plan.md`,
ADR 0092): U1–U8 done. Trivy fix for CVE-2026-111943 (human decision QUESTIONS 77a, ADR 0093) on branch `sec-go-x509`,
merged into `rename-fieldset`, which is merged `--no-ff` into `main`; local tags `rename-done` and annotated `v2.1.0` on
that merge commit. No tag pushed. Gate report `docs/agent/milestones/R2.1.md`, evidence `milestones/evidence/R2.1/`.

GitHub repo renamed to `beyerenrico/fieldset` by the human; they pushed `main` (ff5646ba), then allowed the agent to
push to get CI green ("you are allowed to push … make sure that the CI is green"; never tags, never force). Fixes on
main: `ci-services` (d362fc1f), `ci-e2e-scheduler` (963c07c0), `ci-classic-builder-lock` (c82c31f0: ClassicBuilderSlot,
one classic-builder render at a time, ADR 0083 amendment; Dogfood records container deaths and a timeline) and
`ci-nightly-journeys` (89abe7e2: human decision "Nightly + release", ADR 0091 amendment; sync backoff test and dev
compose start-order flakes). Both branches green on GitHub before the merge (runs 39715804524 with journeys 148 passed /
7 skipped / 3 warnings, and 39728456302). `v2.1.0` stays on ff5646ba; later fixes change app code in the image (QR
backend, stack file, ClassicBuilderSlot).

## Next actions
0. Confirm the `main` CI run after 89abe7e2 and the first `Install journeys` run on main (dispatched by hand) are
   green; later nightly runs: fix a red one before anything else.
1. Human: decide whether `v2.1.0` moves from ff5646ba to a green main commit (its stack file still disables the
   scheduler's healthcheck, which breaks `up --wait` with older Compose). Then trademark check, GHCR first publish, push
   only `v2.1.0` (or a `v2.1.0-…` pre-release) by name, delete old dev resources (QUESTIONS 77c/d, RELEASING.md).
2. Trivy exceptions expire 2026-11-17 (older gotenberg, caddy entries) and 2026-11-20 (CVE-2026-111943 entries for
   postgres, gotenberg, mailpit, caddy; cloudflared CVE-2026-63209). Before any later release: refresh the pins to
   images built with Go ≥ 1.26.10 / 1.27.3, or renew the entries in a reviewed commit.
3. QUESTIONS 77b (PDF fixture producer) still open.

## Gate results (R2.1.md, rerun after the Go fix)
- Static, licenses clean; PHP 7404/7404; Vitest 615/111/622/246; conformance 391/392.
- Install journeys (2.1.0-alpha.1-test) 151 passed / 7 skipped, dogfood 71; 2.1.0 multi-arch build 7/7;
  J1 amd64 5/5; J1 + J7 at 2.1.0 13/13.
- Trivy green: app arm64 + amd64 without exceptions; every third-party image both platforms with its own file.
- E2E dev stack 79 passed / 8 skipped (run 2; run 1 had the known m6-offline click flake).
- No test Docker resources left.

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
- 30-day expiry for the new gosu entry (no reachability analysis for this CVE), not 90 like gosu's older entries.

## Known problems
- E2E runs rewrite evidence PNGs: revert only PNGs (LEARNINGS 159). m6-offline click flake under load (QUESTIONS 76c).
- Dev stack is Compose project `fieldset` (LEARNINGS 163); old `form-schema-app` volumes/images kept for the human.
- The harness build fetches fonts from the network (Vite fonts plugin): a CDN timeout fails the build (LEARNINGS 166).

## Resume commands
COMPOSE_PROFILES=sso docker compose up -d --wait
DB_DATABASE=fieldset_test php artisan test --compact --parallel
FIELDSET_EVIDENCE_MILESTONE=R2.1 FIELDSET_REQUIRE_DOCKER=1 vendor/bin/pest --testsuite=Install
npm run e2e -- --reporter=line --workers=2
vendor/bin/rector --dry-run --no-progress-bar   # must be 0 files
