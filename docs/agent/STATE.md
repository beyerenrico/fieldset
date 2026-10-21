# State: 2026-10-21 — Rename to Fieldset: gate green except Trivy, 2.1.0 not cut
v2 run complete (2.0.0, local tag `v2.0.0`, never published; `docs/agent/FINAL_REPORT_V2.md`).
Rename to Fieldset (human decision, QUESTIONS 1/25; plan `docs/plans/2026-10-21-001-refactor-rename-to-fieldset-plan.md`,
ADR 0092): U1–U7 done on branch `rename-fieldset`; U8 gate report `docs/agent/milestones/R2.1.md`, evidence
`docs/agent/milestones/evidence/R2.1/`. **Not merged to `main`, no `rename-done` / `v2.1.0` tag** (Trivy).

## Next actions
1. Trivy (QUESTIONS 77a): CVE-2026-111943 (Go stdlib crypto/x509, fixed in 1.26.10 / 1.27.3) in every Go binary.
   App image (no exceptions allowed): `docker/app/Dockerfile` golang 1.27.2 → 1.27.3 (age, compose), docker CLI
   29.9.1 → a release built with fixed Go (29.9.2 exists, unverified), FrankenPHP base image rebuilt with fixed Go.
   Third-party: newer pins in `distribution/release.json` or dated exceptions per ADR 0071 where no upstream fix exists.
   Then rescan (scratchpad-style script: release.yml settings, app image local via the socket), rebuild, rerun
   all install journeys (`FIELDSET_EVIDENCE_MILESTONE=R2.1`) + `FIELDSET_PRERELEASE_VERSION=2.1.0`, E2E.
2. When every gate is green: `git checkout main && git merge --no-ff rename-fieldset`, tag `rename-done` and annotated
   `v2.1.0` locally on the merge commit. Never push.
3. Human steps (QUESTIONS 77c/d, RELEASING.md "Pre-publish checklist"): trademark check, GitHub repo rename before
   any tag push, GHCR publish, delete old dev resources.

## Gate results (R2.1.md)
- Static, licenses, audits clean; PHP 7403/7403; Vitest 615/111/622/246; conformance 391/392; Field App build OK.
- E2E dev stack 79 passed / 8 skipped. Install journeys (2.1.0-alpha.1-test) 151 passed / 7 skipped, dogfood 71;
  2.1.0 multi-arch build 7/7; J1 amd64 5/5; J1 + J7 at 2.1.0 13/13. No test Docker resources left.
- Trivy red (only valkey passes); existing exceptions still valid (2026-11-17 / 2026-11-20 / 2027-01-16).

## Decisions taken
- No version file for 2.1.0: `release:version` takes the version as an argument, `release.json` has none.
- Journeys write release evidence to `R<major>.<minor>` (`FIELDSET_EVIDENCE_MILESTONE=R2.1`); M10-pinned journeys
  still write into M10: copy to `R2.1/M10-pinned/` and restore M10 (R6).
- PDF fixtures not regenerated (encrypted variants not reproducible; QUESTIONS 77b).
- `fieldset-ci` rebuilt from `docker/ci/Dockerfile` (no longer a retag).

## Known problems
- Trivy CVE-2026-111943 (above). Trivy exceptions expire 2026-11-17 / 2026-11-20.
- E2E runs rewrite evidence PNGs: revert only PNGs (LEARNINGS 159).
- Dev stack is Compose project `fieldset` (LEARNINGS 163); old `form-schema-app` volumes/images kept for the human.
- The test image `fieldset-test-u1` was removed after the scan; journeys rebuild it.

## Resume commands
COMPOSE_PROFILES=sso docker compose up -d --wait
DB_DATABASE=fieldset_test php artisan test --compact --parallel
FIELDSET_EVIDENCE_MILESTONE=R2.1 FIELDSET_REQUIRE_DOCKER=1 vendor/bin/pest --testsuite=Install
FIELDSET_EVIDENCE_MILESTONE=R2.1 FIELDSET_PRERELEASE_VERSION=2.1.0 vendor/bin/pest --testsuite=Install tests/Install/Journeys/LocalPreReleaseTest.php
npm run e2e -- --reporter=line --workers=2
vendor/bin/rector --dry-run --no-progress-bar   # must be 0 files
