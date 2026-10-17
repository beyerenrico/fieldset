# 0065: App image: ops binaries, slimming and measured size
- Status: accepted
- Date: 2026-10-17

## Context
Plan U1 (M9-T02) turns the 1.0 app image into the 2.0 image that runs every role: app, queue, scheduler, the
`fsa` ops container (KTD1, KTD2) and the backup daemon (KTD15). Requirements: R1 (OCI labels), R5 and E17
(runtime-only content, at most 1.0 GB uncompressed per architecture, ADR 0063), R6 (no permission errors in the
logs), KTD8 (ops binaries, checksum-pinned per `TARGETARCH`) and KTD9 (`APP_VERSION`). ADR 0063 measured the
binaries at about 210–220 MB and named "stop embedding cosign" as the first lever if the budget is missed.
The 1.0 image was 2.12 GB (arm64): `COPY . .` shipped 702 MB of build context (storage/playwright alone is
572 MB), the `chown -R storage` layer copied that again (695 MB), and the PHP base image keeps its build toolchain.

## Decision
**Binaries.** Static release assets, pinned with BuildKit `ADD --checksum=sha256:…` in one stage per architecture
(`downloads-amd64`, `downloads-arm64`), selected with `FROM downloads-${TARGETARCH}`. A mismatch fails the build.

| Tool | Version | Source | SHA-256 amd64 / arm64 | Checksum source |
|---|---|---|---|---|
| Docker CLI | 29.9.1 | `download.docker.com/linux/static/stable/{x86_64,aarch64}/docker-29.9.1.tgz` (only `docker/docker` is kept) | `c158ecc5…f57` / `3e93cc6d…018` | Docker publishes no checksums for the static archives. The pins are our own hashes; the extracted binary is byte-identical, on both architectures, to `/usr/local/bin/docker` in the official `docker:29.9.1-cli` image (index `sha256:6fae2214…1800`) |
| Compose | 5.6.0 | `github.com/docker/compose/releases/download/v5.6.0/docker-compose-linux-{x86_64,aarch64}` → `/usr/local/lib/docker/cli-plugins/` | `40343e21…76a` / `733ec767…ea2` | `checksums.txt` and the per-file `.sha256` of the release, matching the GitHub asset digests |
| cosign | 3.1.3 | `github.com/sigstore/cosign/releases/download/v3.1.3/cosign-linux-{amd64,arm64}` | `4629c757…f71` / `c5d324e0…20a` | `cosign_checksums.txt`, matching the GitHub asset digests |
| age, age-keygen, age-plugin-batchpass | 1.3.2 | `github.com/FiloSottile/age/releases/download/v1.3.2/age-v1.3.2-linux-{amd64,arm64}.tar.gz` | `cbe24006…c10` / `6b8dc433…7f4` | GitHub asset digests (the release has no checksum file; it ships Sigsum `.proof` files, not checked in the build) |
| Postgres client | 16 (16.15-1.pgdg12+2 at build time) | PGDG apt, deb822 source without an architecture field | key `ACCC4CF8.asc` pinned at `01440685…e76`, fingerprint `B97B 0AFC AA1A 47F0 44F2 44A0 7FCC 7D46 ACCC 4CF8` | apt verifies packages against the pinned key; the version is not pinned because PGDG keeps only the current minor |

Full checksums are in `docker/app/Dockerfile`. age-plugin-batchpass ships in the age release archive since
1.3.0 (release notes: "Non-interactive passphrase input … through the batchpass plugin"); age-inspect and the
compatibility plugins (pq, tag, tagpq) are left out. The Apache-2.0 LICENSE (and Docker CLI's NOTICE) of Docker
CLI, Compose and cosign are fetched with pinned checksums from the tagged sources, age's LICENSE comes from its
archive; all land in `/usr/share/doc/<tool>/`. `postgresql-client-common` depends on `perl` (about 50 MB).

**Slimming.**
- `.dockerignore` is an allow-list of what the stages read; tests, docs, scripts, `.github`, agent files,
  storage contents, package tests, `node_modules` and `vendor` never reach the builder.
- A `build` stage assembles the application tree (only `resources/views` and `resources/fonts`, the PHP package
  `src`, `spec/form-definition/1.0` and `spec/templates`), runs `composer dump-autoload` and `filament:assets`,
  and the final stage copies that tree. Composer exists only in the `build` stage.
- The base is flattened. A `runtime-build` stage adds the extensions, qpdf, curl, `media-types` (Caddy reads
  `/etc/mime.types`) and the Postgres client. It then marks every package auto except Debian's required and
  important ones, those runtime packages and the libraries that `/usr/local` links (docker-library's own
  `apt-mark` pattern), and purges the rest: compilers, headers, binutils and cmake leftovers. It also deletes
  `php-cgi`, `phpdbg` and the PHP headers. `FROM scratch` + `COPY --from=runtime-build / /` makes the purge
  count; the build fails if an extension or a shared library is missing afterwards.
- FrankenPHP loses its `cap_net_bind_service` file capability (the app listens on 8080), so the app also starts
  with `cap_drop: [ALL]` (checked). `/var/www/html` is root-owned 0755; only `storage/` and `bootstrap/cache`
  belong to `www-data`. The base's labels and its failing `HEALTHCHECK` (admin endpoint on 2019, which is off)
  are gone; compose defines health checks per service.

**Roles, logs, version.**
- The entrypoint runs `optimize` (and migrations with `RUN_MIGRATIONS=true`) only for the app roles. `fsa …`
  (`/usr/local/bin/fsa`, a wrapper for `php artisan fsa:<command>`), `php artisan fsa:…` and the backup daemon
  (`fsa backup-daemon`) skip both: they run as other users, often without a database, and must not write
  `bootstrap/cache`.
- R6: `XDG_CONFIG_HOME=/tmp/xdg-config` and `XDG_DATA_HOME=/tmp/xdg-data` (writable for any UID, and on a tmpfs
  under a read-only root), plus `persist_config off` in the Caddyfile. The app's start-up logs carry no
  `permission denied` and no error line (journey). psysh (`tinker`) no longer needs `-e XDG_CONFIG_HOME` either.
- `APP_VERSION` (default `0.0.0-dev`) and `APP_REVISION` build arguments feed the OCI labels (`version`,
  `revision`, plus `title`, `description`, `source`, `url`, `licenses=AGPL-3.0-only`) and the environment, which
  `config('app.version')` and `config('app.revision')` read. They come last in the Dockerfile, so a version bump
  rebuilds no layer above them.

**cosign stays in the image** (KTD8 and ADR 0060 unchanged): the budget holds with it on both architectures
(below): 648 MB with cosign leaves about 350 MB of headroom, so ADR 0063's lever (about 130–140 MB) is not
needed.

## Measurements (2026-10-17, Docker Desktop 29.2.1 on Apple silicon)
| | arm64 (native) | amd64 (emulated) |
|---|---|---|
| 1.0 image (`form-schema-app:local`) | 2,118,533,446 B (2.12 GB) | not measured |
| 2.0 image, uncompressed (`docker image inspect .Size`) | 647,845,526 B (648 MB) | 647,062,684 B (647 MB) |
| without cosign (image minus the cosign binary) | 515,098,123 B (515 MB) | 505,884,434 B (506 MB) |
| compressed (`docker save` piped through `gzip -6`) | 212,520,522 B (213 MB) | 225,961,587 B (226 MB) |
| build with the base images already pulled | 92 s | 6 min 37 s |

Layers: flattened runtime 334 MB (arm64) / 319 MB (amd64), binaries 189 / 201 MB, Compose 30.5 / 32.7 MB,
application tree 94 MB. Both images pass the image contract journey (amd64 with `FSA_TEST_PLATFORM=linux/amd64`).
`tests/Install/Journeys/ImageContractJourneyTest.php` writes the measured size to
`storage/agent-logs/fsa-test-u1-size-<arch>.txt` for the M9 evidence.

## Alternatives considered
- **Copy `/usr/local` onto `debian:bookworm-slim` and install the libraries `ldd` reports:** a clean layer
  history, but libraries that are only `dlopen`ed would be missed. The purge keeps docker-library's own runtime
  marks.
- **Alpine base:** much smaller, but musl, and FrankenPHP recommends its glibc images for performance; PGDG is
  Debian-only.
- **`COPY --from=docker:29.9.1-cli@sha256:…`:** content-addressed as well, but KTD8 asks for release binaries
  pinned per architecture; the official image serves as the cross-check instead.
- **Drop cosign (ADR 0063 lever), or a separate ops image:** not needed while the budget holds; see ADR 0063.
- **Unpack the Postgres client from its `.deb` to avoid perl:** saves about 50 MB but bypasses apt's
  dependencies and security updates.

## Consequences
- Updating a tool means changing its URLs and both checksums together; the image contract test checks that each
  tool stays on its KTD8 series (29.9.x, 5.6.x, 3.1.x, 1.3.x) and that every architecture has four distinct pins.
- The flattened runtime is one layer of about 334 MB. It shares nothing with the upstream FrankenPHP image, and
  it changes only when the base, the extensions or the apt packages change.
- `tests/Install` is its own PHPUnit suite (`Install`), outside the default run and coverage
  (`defaultTestSuite="Unit,Feature"`). It needs Docker and skips without it (`FSA_REQUIRE_DOCKER=1` makes that a
  failure); `FSA_TEST_PLATFORM=linux/amd64` runs it under emulation.
- The license check (§10, ADR 0060) still has to cover the copied binaries and allow SPDX `PostgreSQL`.
