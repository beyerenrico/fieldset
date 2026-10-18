# 0048: The app image is the installer (ops container)
- Status: accepted; amended by ADR 0071 (Compose and age built from source, no cosign in the image)
- Date: 2026-10-17

## Context
E2 and §2.4: only Docker on the host, and management logic under the same tests and static analysis as the
product. Today the image cannot run as a CLI container: it runs as `www-data`, and its entrypoint always runs
`artisan optimize` (plan, Problem Frame). ADR 0020 made one FrankenPHP image for app, queue and scheduler.

## Decision
- All management logic is Laravel console code in the app image: `fsa:*` artisan commands behind a two-line `fsa`
  wrapper, with domain code in `app/Domain/Instance` (plan KTD1). Docker is reached only through a `DockerGateway`
  interface, implemented with Symfony Process. Unit tests use a recording fake; install journeys use the real CLI.
- Every command runs in a throwaway ops container of the pinned image (`docker run --rm`). The container gets the
  Docker socket, and the instance directory mounted at the same absolute path (§6.2).
  - It runs as the host UID. On rootful Docker it adds the socket's group; on rootless Docker and Docker Desktop it
    runs as container root.
  - It sets `HOME` and `DOCKER_CONFIG` under `/tmp` and bypasses the entrypoint (KTD2).
- Upgrade, restore, adopt and reset run their supervisor as a detached ops container of the installed image
  (KTD5), so a dropped SSH session does not kill it.
- Only ops and supervisor containers get the socket, and only for one command. The socket holder never opens a
  database or cache connection. Migrations, `app:install`, sign-in links, seeding, the operator grant and audit
  writes run as `compose run` one-offs without the socket (KTD25).
- `install.sh` is POSIX sh, about 150 lines. It checks Docker, verifies the release manifest (ADR 0060), pulls the
  app image by digest, writes the shim and hands over to `fsa install`. Host facts (TTY, OS, LAN candidates,
  desktop session, UID:GID, TZ) arrive as environment variables (§6.2).
- The image carries static, checksum-verified binaries per `TARGETARCH` (KTD8): Docker CLI and Compose
  (ADR 0051), PGDG `postgresql-client-16` and age (ADR 0059), and cosign (ADR 0060).
  (Amended 2026-10-18 by ADR 0071: Compose and age are built from source at a pinned tag and commit, verified by
  go.sum; only the Docker CLI stays a checksum-pinned release binary. cosign is not in the image: `install.sh`
  and `fsa` verify with the pinned official cosign image, ADR 0070.)
- The image now has three roles: app service, ops container and backup daemon. This amends ADR 0020.

## Alternatives considered
- **Shell installer plus shell helper:** the logic would sit outside Pest, Larastan and coverage, and idempotence
  and JSON output would be hard to get right.
- **Standalone Go CLI:** a second toolchain, release pipeline and test stack, with version skew against the app.
- **Separate `bin/fsa` console kernel:** isolates the ops role but duplicates the console and test helpers
  (KTD1). Instead, provider boot never opens database or cache connections, and every `fsa:*` command except
  `fsa:version` refuses to run without the instance-directory marker.
- Prior art cited by the plan: Appwrite (installer image with socket), Coolify (same-path mount), Nextcloud AIO.

## Consequences
- Each version ships its own upgrade steps. The installed version supervises and owns the rollback (ADR 0058).
- The socket is root-equivalent, and the docs say so plainly (§10). The instance directory is 0700, and the doctor
  checks its permissions.
- The binaries add size (ADR 0063). Trivy scans them too, so a Go standard-library CVE can block a release.
- Coverage of `app/Domain/Instance` must be at least 90 % (§11).
