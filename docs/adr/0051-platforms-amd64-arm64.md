# 0051: Platforms: linux/amd64 and linux/arm64
- Status: accepted; amended by ADR 0071 (Compose built from source, no cosign in the image), ADR 0072
  (Docker floor) and ADR 0095 (WSL 2 supported and tested by hand per release)
- Date: 2026-10-17

## Context
E5. Apple Silicon laptops and ARM VPS (for example Hetzner CAX) are common targets. The ops container runs the
Docker CLI and Compose against the host daemon (ADR 0048).

## Decision
- Images are built for linux/amd64 and linux/arm64 with buildx (ADR 0060).
- Supported hosts: Ubuntu 22.04 and 24.04, Debian 12, and macOS with Docker Desktop or OrbStack. Windows through
  WSL2 is documented but not tested. Native Windows is a non-goal (§4.2).
  (Amended 2026-10-26 by ADR 0095: from 2.1.1, Windows through WSL 2 is supported with Docker Desktop's WSL integration
  or Docker Engine inside the distro, documented in the install guide's Windows section and tested by hand on every
  2.1.x release candidate with RELEASING's WSL checklist (a full run on Docker Desktop, a short one on Docker Engine);
  CI has no WSL run. WSL 1 is refused with exit 3 `wsl1_unsupported`. Native Windows is still a non-goal.)
- The ops container brings its own Docker CLI and Compose plugin. The host only needs Docker Engine and the
  `docker` CLI to start the ops container, so the host's Compose version does not matter to `fsa`. The binaries
  are static, chosen per `TARGETARCH`, and pinned by SHA-256 in the Dockerfile:

| Binary | Version | Source (verified 2026-10-17) | License |
|---|---|---|---|
| Docker CLI | 29.9.x (29.9.1, 2026-10-14) | `download.docker.com/linux/static/stable/{x86_64,aarch64}/docker-29.9.1.tgz` (moby release `docker-v29.9.1`); only `docker/docker` is copied | Apache-2.0 |
| Compose plugin | 5.6.x (5.6.0, 2026-10-02) | github.com/docker/compose release assets `docker-compose-linux-{x86_64,aarch64}`, installed to `/usr/local/lib/docker/cli-plugins/` | Apache-2.0 |

(Amended 2026-10-18 by ADR 0071: Compose, like age, is built from source, tag `v5.6.0` at a pinned commit and
verified by go.sum, and cross-compiled per `TARGETARCH`. Only the Docker CLI stays a release binary pinned by
SHA-256. cosign is not in the image.)

- Minimum host Engine: the doctor (R10) reads the daemon and API versions and exits 10 below a floor that M9-T02
  and M9-T08 fix from what the pinned CLI negotiates. The proposed floor is Engine 25.0 (API 1.44).
  - Docker's 29.x release notes say the 29.0 daemon required API 1.44, and 29.3 lowered that to 1.40
    (docs.docker.com/engine/release-notes/29, verified). Both concern old clients talking to a new daemon.
  - Unverified: whether CLI 29.9 refuses an older daemon.
  - (Amended 2026-10-18 by ADR 0072: the floor is **Engine 24.0** (API 1.43) and **Compose 2.24**,
    `DockerVersionCheck::MIN_ENGINE` and `MIN_COMPOSE`. Below either, the doctor fails with exit 10. The Compose
    plugin ships in the image, so in practice only the Engine can be too old.)
- Docker socket: Docker Desktop for macOS keeps the socket at `~/.docker/run/docker.sock` and can symlink
  `/var/run/docker.sock` (docs.docker.com, Mac permission requirements, verified). `DOCKER_HOST` is honoured
  (§6.2) only as a `unix://` socket, on rootful and rootless Docker; any other scheme exits 10
  (`docker_host_unsupported`), and on Docker Desktop, OrbStack or Colima the shim always mounts the VM's
  `/var/run/docker.sock` (as built, ADR 0070). The OrbStack and Colima socket paths and rootless detection are
  found by the doctor (plan Open Questions; unverified).
- Install journeys run on amd64 in CI and on arm64 locally. On a single-architecture host, J1 also runs under
  emulation for the other one, and the evidence records this (§15.6).

## Alternatives considered
- **amd64 only:** excludes Apple Silicon laptops and ARM VPS.

## Consequences
- Every binary in the image needs a checksum per architecture, and a refresh is a deliberate commit (R4).
  (Amended by ADR 0071: Compose and age are pinned by tag, commit and go.sum instead; the Docker CLI keeps its
  per-architecture checksums.)
- Docker Desktop with Enhanced Container Isolation rejects socket mounts. The doctor detects this and names the
  setting (plan Risks). A directory that Docker Desktop or Colima does not share fails the shim's nonce check
  with exit 3.
- WSL2 and Docker Desktop quirks go to `docs/guide/troubleshooting.md` and QUESTIONS, never into silent
  workarounds (§15.7). (Amended 2026-10-26 by ADR 0095: the troubleshooting guide has a WSL section; the Windows facts
  still unverified are QUESTIONS 81.)
- Multi-arch builds and emulated journeys lengthen CI.
