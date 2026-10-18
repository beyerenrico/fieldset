# 0071: Image binaries built from source, no cosign in the image, and the image scan policy
- Status: accepted; the exception policy for third-party images waits for a human to confirm it (QUESTIONS 27)
- Date: 2026-10-18
- Supersedes the binary table of ADR 0065. Amends ADR 0048 (the image's binaries), ADR 0051 (Compose from
  source), ADR 0060 (cosign in the image, licenses), ADR 0063 (the cosign lever) and ADR 0067 (the scan gate).

## Context
The M9 gate needs the release scan to pass: Trivy 0.76.0 with `--severity CRITICAL,HIGH --ignore-unfixed
--exit-code 1` on the app image and on every third-party digest (plan U7, ADR 0060, ADR 0067). On 2026-10-18 it
failed (QUESTIONS 27), and no newer upstream release of any affected component existed (checked that day):
- App image, 34 HIGH, all in vendored Go release binaries:
  - cosign 3.1.3: 21 (12 in the Go 1.26.4 standard library; 9 in x/crypto, x/mod, x/net, x/text and grpc).
  - Compose 5.6.0: 4 (3 in the Go 1.26.8 standard library; 1 in x/net 0.58.0, fixed in 0.60.0).
  - age, age-keygen and age-plugin-batchpass 1.3.2: 9 (Go 1.27.0 standard library).
  - Docker CLI 29.9.1, the Debian packages, Composer packages and FrankenPHP: none.
- Third-party pins, all already the newest digests:
  - postgres 16.15-alpine: 1 CRITICAL + 24 HIGH, all in gosu 1.19 (the latest gosu).
  - gotenberg 8.38.1: 87 HIGH (77 in Debian's chromium and libexpat1, fixed in trixie-security; 10 in Go).
  - caddy 2.11.7: 4 HIGH (Go standard library, x/net).
  - cloudflared 2026.10.1: 1 HIGH (libssl3t64, linux/amd64 only).
  - valkey and mailpit: none.

Since ADR 0070, `fsa` verifies release manifests with the pinned official cosign image, like `install.sh`, so the
cosign binary in the image had no user left (QUESTIONS 28).

## Decision

**cosign leaves the image.** `fsa` and `install.sh` verify with `ManifestVerifier::COSIGN_IMAGE`
(`ghcr.io/sigstore/cosign/cosign:v3.1.3@sha256:…`), and the release harness verifies with the same image
(`tests/Support/Docker/Cosign.php`). The signer identity (R58) is unchanged. An offline use does not justify the
binary: keyless verification fetches Sigstore's trusted root through TUF either way (ADR 0070). This is ADR 0063's
first size lever, taken for the scan rather than the budget, and it amends ADR 0060's "`fsa` uses the cosign
binary in the image".

**Compose and age are built from source** in `docker/app/Dockerfile`:
- Toolchain `golang:1.27.2-bookworm@sha256:5cf287a799e6b94384bad13d16b14904c531f51ba65792237e122ce42b392f61`
  (index digest, verified with `docker buildx imagetools inspect` on 2026-10-18).
- The Go stages run with `FROM --platform=$BUILDPLATFORM` and cross-compile with `GOOS=linux
  GOARCH=$TARGETARCH`, so a multi-arch build never emulates the toolchain. The assembling `tools` stage only
  unpacks and copies, so it runs on the build platform as well.
- Each source stage clones the release tag and stops unless `git rev-parse HEAD` equals the pinned commit. It then
  downloads the modules, which go.sum verifies. The build stages run with `GOPROXY=off` from that module cache.
- Flags: `CGO_ENABLED=0 GOTOOLCHAIN=local -trimpath -ldflags "-s -w -buildid= -X <version>"`. Compose builds
  `./cmd` with `-tags e2e`, as its Makefile does; age builds `./cmd/{age,age-keygen,age-plugin-batchpass}`.
- The version strings stay upstream's: `docker compose version` prints `v5.6.0`, and each age tool prints `v1.3.2`.
- Compose needs one module bump inside the build stage: `go get golang.org/x/net@v0.60.0` and `go mod tidy`. That
  moves x/net 0.58.0 to 0.60.0, x/crypto 0.56.0 to 0.57.0 and x/text 0.41.0 to 0.42.0, and changes nothing else.
  go.mod and go.sum after the bump are pinned by SHA-256 in the Dockerfile. The bump goes away once a Compose
  release requires x/net 0.60.0.
- Compose's LICENSE and NOTICE and age's LICENSE come from the verified source tree. Compose's NOTICE was missing
  before. Docker CLI's LICENSE and NOTICE are still fetched with pinned checksums.

**The Docker CLI stays the upstream static release binary**, pinned by SHA-256 per architecture (no findings;
Docker builds it with Go 1.26.9).

Binary table (replaces ADR 0065's):

| Software | Version | Origin and pin | License |
|---|---|---|---|
| Docker CLI | 29.9.1 | `download.docker.com/linux/static/stable/{x86_64,aarch64}/docker-29.9.1.tgz`, SHA-256 `c158ecc5…f57` / `3e93cc6d…018` | Apache-2.0 |
| Docker Compose | 5.6.0 | built from `github.com/docker/compose` tag `v5.6.0`, commit `42f48072bbf92ee9b0e43f9fdf2008d03546e7ca`; go.mod `4fc4a43a…748c`, go.sum `88c9f2d7…470d` after the bump | Apache-2.0 |
| age, age-keygen, age-plugin-batchpass | 1.3.2 | built from `github.com/FiloSottile/age` tag `v1.3.2`, commit `b74dce4cdbe35b5e5f66c06d9612b72f89028758` | BSD-3-Clause |
| postgresql-client-16, with libpq5 and postgresql-client-common | 16 (16.15-1.pgdg12+2, libpq5 18.6, common 293 at build time) | PGDG apt, signing key pinned (ADR 0065) | PostgreSQL; postgresql-client-common is GPL-2.0-or-later |
| phpredis | PECL stable (6.3.0 at build time) | `install-php-extensions` | PHP-3.01 |
| cosign | removed | | |

**Inventory and license check.** `docker/app/binaries.json` lists this software with name, version, origin,
source, license (SPDX), image paths and license files.
- `tests/Unit/Docker/ImageBinariesTest.php` keeps it and the Dockerfile in step, in both directions: every clone,
  download, PGDG and PECL install is listed, with the same versions, sources and commits.
- The image contract journey checks every path and license file in the built image.
- `scripts/check-licenses.php` checks the inventory after the Composer packages; `--binaries-only` checks only the
  inventory. The allow-list gains `PostgreSQL` and `GPL-2.0-or-later`. Both are OSI-approved, and GPL-2.0-or-later
  code may be used under GPL-3.0, which is compatible with AGPL-3.0. The latter covers `pg_wrapper`, a separate
  Perl program.
- Out of scope: Debian's own packages (their copyright files ship in `/usr/share/doc`) and the FrankenPHP base
  image (FrankenPHP is MIT, PHP is PHP-3.01, both on the allow-list).

**Scan policy.**
- **What we build is scanned strictly.** The app image scan in `ci.yml` and `release.yml` uses no exception file
  (`--ignorefile ''`). A unit test fails if an exception file for the app, or a global `.trivyignore`, appears.
- **Third-party images may carry dated, scoped exceptions**, but only for findings that no released upstream image
  fixes. They live in `distribution/trivy/<service>.trivyignore.yaml`, one entry per finding id. Each entry has:
  - a scope: `paths` (the binary, for example `usr/local/bin/gosu`) or `purls` (the package, without a version);
  - a `statement`: why we cannot fix it, the upstream status, and reachability where it is known;
  - an `expired_at` at most 90 days out. 90 days only for findings shown unreachable whose upstream treats them as
    false positives; 30 days when a fix exists upstream and only the image release is missing.
- `release.yml` scans every image with only its own file and `--show-suppressed`, which lists each suppressed
  finding with its statement. An expired entry stops suppressing, so the release fails again. Renewing an entry is
  a reviewed commit with a new statement. valkey and mailpit have no file.
- `tests/Unit/Release/ImageScanPolicyTest.php` checks the files: id, scope, statement, expiry within 90 days, one
  file per third-party service and none for the app. It also checks how the workflows pass them.

Current exceptions (findings per platform):

| Service | Findings | Entries | Scope | Expires | Reason |
|---|---|---|---|---|---|
| postgres | 25 (1 critical, 24 high) | 25 | `usr/local/bin/gosu` | 2027-01-16 | Go 1.24.6 stdlib in gosu 1.19, the latest. Per the M9 research of 2026-10-18: govulncheck finds no reachable call, and gosu's SECURITY.md and the docker-library FAQ call such findings false positives |
| gotenberg | 87 high | 47 | chromium, chromium-common and libexpat1 by purl; `usr/bin/gotenberg`, `usr/bin/pdfcpu` | 2026-11-17 | fixed in trixie-security (155.0.8059.79-1~deb13u1, 2.9.0-1~deb13u1), Go 1.27.2, x/net 0.60.0, x/image 0.45.0, x/text 0.41.0 and x/crypto 0.55.0; waiting for a Gotenberg image release; reachability not analysed |
| caddy | 4 high | 3 | `usr/bin/caddy` | 2026-11-17 | Go 1.26.8 stdlib and x/net 0.59.0, fixed in Go 1.26.9/1.27.2 and x/net 0.60.0; **reachable** per govulncheck (M9 research); accepted only until the next Caddy release |
| cloudflared | 1 high (linux/amd64) | 1 | `pkg:deb/debian/libssl3t64` | 2026-11-17 | fixed in 3.5.7-1~deb13u3, waiting for a cloudflared image release; reachability not analysed; the tunnel is off by default |

Tested with Trivy 0.76.0 on 2026-10-18 against the caddy pin:
- An entry whose path does not match is not applied.
- An entry whose `expired_at` has passed is not applied.
- A purl without a version (`pkg:golang/stdlib`) matches the installed version.

## Measurements (2026-10-18, Docker Desktop 29.2.1 on Apple silicon)
| | arm64 (native) | amd64 (emulated final stages, cross-compiled Go) |
|---|---|---|
| before (m9-release `3f5123e`, `docker image inspect .Size`) | 648,107,202 B | 647,289,426 B |
| after | 507,086,219 B (−141.0 MB) | 497,455,098 B (−149.8 MB) |
| compressed (`docker save` piped through `gzip -6`), before → after | 212,583,093 → 155,840,027 B | 226,018,428 → 164,204,626 B |
| cosign removed | 132,747,403 B | 141,178,250 B |
| Compose, release → source build (`-s -w`) | 30,538,254 → 27,066,492 B | 32,663,526 → 29,188,220 B |
| age + age-keygen + age-plugin-batchpass | 14,357,770 → 9,568,628 B | 15,252,820 → 10,084,724 B |

- The Go stages take about 40 s per architecture without cache.
- Two `--no-cache` builds of the `tools` stage were byte-identical on each architecture. Compose SHA-256 was
  `3e715e7a…464f` (arm64) and `c07900d5…ef6c` (amd64); age was `8f27c451…0a4` (arm64) and `b2b67a6c…e47` (amd64).
- The file times in the layer still differ between builds; only the binaries are reproducible.

Trivy 0.76.0 (database of 2026-10-17), CI flags, both platforms:

| Image | Found | Suppressed | Remaining |
|---|---|---|---|
| app (before) | 34 high | 0 | 34 |
| app (after) | 0 | 0 | 0 |
| postgres | 25 | 25 | 0 |
| gotenberg | 87 | 87 | 0 |
| caddy | 4 | 4 | 0 |
| cloudflared | 1 (amd64), 0 (arm64) | 1 | 0 |
| valkey, mailpit | 0 | 0 | 0 |

The rebuilt binaries are not invisible to the scanner: Trivy still reads their build information (for example
stdlib v1.27.2, x/net v0.60.0 in Compose) and finds nothing.

## Alternatives considered
- **Exceptions for the app image's binaries:** we can fix those findings ourselves by rebuilding, so what we build
  stays strict.
- **Building the Go stages under QEMU:** the amd64 image took 6 min 37 s emulated (ADR 0065). Cross-compiling is
  native speed, and its output is reproducible.
- **Upgrading every Compose dependency (`go get -u`):** moves away from what upstream tested. Only the bump for a
  finding is made.
- **A global `.trivyignore`:** it would apply to every image, the app included, with no scope or expiry.
- **A Rego ignore policy:** more power than this needs, and harder to review than a list of findings.
- **Narrower severity or no exit code for third-party images:** new findings would go unnoticed.
- **Keeping cosign for offline verification:** keyless verification needs the network anyway (TUF).

## Consequences
- The image is about 141 MB (arm64) and 150 MB (amd64) smaller, and the app image scan passes without exceptions.
- KTD8 changes: the Docker CLI stays pinned by checksum per architecture, while Compose and age are pinned by tag,
  commit and go.sum.
  - Updating Compose or age means changing the tag, the commit and the `-X` version together, and refreshing the
    go.mod/go.sum hashes while the bump exists. `docker/app/binaries.json` and the tests follow.
  - A new Go patch release that fixes a standard-library CVE means bumping the golang digest and rebuilding.
- The exceptions expire on 2026-11-17 (gotenberg, caddy, cloudflared) and 2027-01-16 (postgres). A release after
  those dates fails at `scan` unless the pins are refreshed to fixed images or a reviewed commit renews the entries.
- QUESTIONS 28 is resolved. QUESTIONS 27 stays open until a human confirms the exception policy.
- The base image ships no license text for FrankenPHP (MIT) and PHP (PHP-3.01). This was already so before this
  ADR, and it is not fixed here.
