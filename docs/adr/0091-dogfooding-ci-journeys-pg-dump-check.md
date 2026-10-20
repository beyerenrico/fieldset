# 0091: Dogfooding the E2E suite, all journeys in CI, the pg_dump release check
- Status: accepted
- Date: 2026-10-20
- Refines: ADR 0067 (release tooling), ADR 0083 (E16 measurement), ADR 0084 (backups)

## Context
Plan unit U22 (PLAN M13-T03). BUILD_PROMPT_V2 §11 asks for the E2E suite to run against a local-mode `--demo` instance
installed by `fsa` from the local release, with journeys that need dev-only services or settings left on the dev stack,
and for the install journeys to run in CI on amd64. R39 asks for a release check that fails when the app image's
`pg_dump` major is below the manifest's Postgres major. The M12 gate measured the backup service at 68 MiB with demo
data only (ADR 0083 amendment); the 1.0 data volume was still unmeasured.

The suite assumed the checkout's dev stack: artisan through `docker compose exec app` in the working directory, Mailpit
on 18025 without a login, open registration, the SSO demo seed, Keycloak, and Horizon open to everyone in
`APP_ENV=local`. Its screenshots go to relative paths in the committed evidence of M0–M8.

## Decision
- **Target selection (tests/e2e/support/platform.ts, target.ts).** `E2E_TARGET=fsa` marks an instance (default: the dev
  stack). `E2E_COMPOSE_PROJECT` names the Compose project whose running app container runs artisan (`docker exec` on
  the container found by the Compose labels, never a one-off); unset keeps `docker compose exec` in the checkout.
  `E2E_BASE_URL` is the instance URL; `MAILPIT_URL` and `MAILPIT_AUTH` (`user:password`, from the instance's
  `env/mailpit.env`, sent as Basic auth, never logged) reach its catcher.
- **What stays on the dev stack** (`devStackOnly(reason)`, a Playwright skip with the reason `dev stack only: …`):
  `m7-sso.spec.ts` (5 tests) and `m7-saml.spec.ts` (1): the dev Keycloak, `SSO_ALLOW_INSECURE_IDP` and the
  `SsoDemoSeeder` data; `m4-onboarding.spec.ts` (1): organization sign-up needs `APP_ALLOW_REGISTRATION=true`, which an
  instance never sets; `m8-accessibility.spec.ts` "login: SSO choice" (1): the SAML demo organization. No spec needed
  config-backed DNS. Everything else runs on both targets. The Horizon CSP check in `m8-csp.spec.ts` signs in as a
  fresh operator with MFA on an instance (Horizon sits behind the operator session in production, ADR 0068) instead of
  being skipped.
- **The journey (tests/Install/Journeys/DogfoodE2ETest.php).** Installs `--demo` in local mode from the harness release
  (install.sh, the same machinery as J1–J12, everything `fsa-test-dogfood…`), runs Playwright's `desktop` project with
  two workers and the JSON reporter, and uninstalls. Playwright runs in a working directory in the harness workspace
  (`tests` linked back, the evidence directories created empty): screenshot paths are relative to the working
  directory, so the run never rewrites committed PNGs (LEARNINGS 159). The journey fails on any failed test, on fewer
  than 60 passes, on a skip whose reason is not `dev stack only:` (or the J4-only capture-banner spec), and when the
  `/platform` instance page's axe test (plan U20) did not pass. Its evidence, `dogfood-e2e.json`, lists per spec file
  what passed, what was skipped and why, and what failed.
- **CI (`install-journeys`, `.github/workflows/ci.yml`).** Runs the whole Install suite on `ubuntu-24.04` (amd64) with
  `FSA_REQUIRE_DOCKER` and `FSA_REQUIRE_PLAYWRIGHT`, so a missing tool fails instead of skipping; installs Node,
  Playwright's Chromium and poppler; checks out the full history and creates the local tag `v1.0.0` on the 1.0.0
  release commit when the remote has none (release tags are pushed by a human, RELEASING.md); timeout 300 minutes
  (the native arm64 suite took 1 h 31 m in M12). Evidence of the run is uploaded with the logs. Only
  `LocalPreReleaseTest` skips there (it builds a named pre-release at a gate).
- **R39 (`release:check-pg-dump`, PgDumpCompatibility).** Reads the manifest, runs the app image once per manifest
  platform as `docker run --rm --platform <p> --network none --entrypoint pg_dump <image> --version`, and fails (exit 1,
  the reason on stderr) when the client major is below the postgres pin's tag major (`16.15-alpine` → 16), when the
  image cannot run, or when it prints no pg_dump version. The release workflow's manifest job runs it after
  `release:manifest` and before the unsigned artifact is uploaded (QEMU for arm64, a read-only GHCR login); the local
  harness runs it on every publish, so every journey and `LocalPreReleaseTest` exercise it. A newer client than the
  server is accepted (pg_dump supports older servers). It is release tooling, not an `fsa` path, so it uses the
  CommandRunner seam rather than DockerGateway.
- **The backup slot at the 1.0 volume (J10).** J10 now schedules a backup after its perf scripts, on the 1.0 test
  volume, and samples the backup container's anonymous and shared memory every second. Measured natively on arm64
  (evidence/M13/J10-backup.json): database 1269 MiB, unencrypted backup 112 MiB in 12 s, unreclaimable peak 77.7 MiB,
  `memory.peak` 128.3 MiB with 894 `max` events and no OOM kill. The peak at the limit is page cache from writing the
  archive, which the kernel reclaims there; the margin rule of ADR 0083 applies to unreclaimable memory:
  77.7 + 30 = 107.7 MiB ≤ 128 MiB. The 128 MiB slot stays; no budget or code change.

## Consequences
- The M13 gate report can say which journeys ran where from the evidence: on the instance 71 passed and 8 were skipped
  as dev stack only (dogfood-e2e.json); the 8 run on the dev stack (`npm run e2e`).
- Adding a dev-only dependency to a spec now needs a `devStackOnly` reason, or the dogfooding journey fails.
- The CI install job is long (hours); it runs only on main and milestone branches, as before.
- A future Postgres major bump in `distribution/release.json` without `postgresql-client-<major>` in the Dockerfile
  stops the release before anything is signed or published.
- The backup slot has 20 MiB less headroom at the 1.0 volume than with demo data (77.7 vs 59.6 MiB unreclaimable);
  a much larger database or encryption could move it. J10 measures it again on every run and reports the verdict.
