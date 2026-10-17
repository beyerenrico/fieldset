# 0050: Images on GHCR, release assets on GitHub Releases
- Status: accepted
- Date: 2026-10-17

## Context
E4. Releases need a registry that supports multi-arch images, attestations and signatures, plus a place for the
release assets. The GitHub owner is `beyerenrico`. Third-party images are pulled from their upstream registries.

## Decision
- The app image is `ghcr.io/beyerenrico/form-schema-app`, multi-arch, tagged as in ADR 0047.
- Release assets go to the GitHub Releases of `beyerenrico/form-schema-app`: `install.sh`, the release manifest,
  the stack file, the agent guide, checksums, and the cosign bundles for the manifest and `install.sh` (KTD22).
- `FSA_REGISTRY` and `FSA_RELEASE_URL` override both for mirrors and for the test harness (ADR 0064). An override
  changes where the bytes come from, never who must have signed them. The signer identity is built into
  `install.sh` and the image (ADR 0060, plan R58).
- Third-party images (Postgres, Valkey, Gotenberg, Caddy, Mailpit, cloudflared) stay on their upstream
  registries, pinned by digest in the manifest (R4). Mirroring them into GHCR is follow-up work (plan, Scope
  Boundaries).
- `fsa` installs and upgrades only from manifest digests, never from moving tags (§6.5).

Verified 2026-10-17:
- GHCR: "Once you make a package public, you cannot make it private again" (docs.github.com, configuring a
  package's access control and visibility). Anonymous pulls need a public package, so making it public is a
  deliberate human step in `RELEASING.md` (ADR 0064).
- Docker Hub limits unauthenticated pulls to 100 per IPv4 address or IPv6 /64 subnet per six hours
  (docs.docker.com/docker-hub/usage).

## Alternatives considered
- **Docker Hub for the app image:** the pull limit would apply to every anonymous install, and it needs a separate
  account and token.
- **Self-hosted registry:** we would carry its availability and running costs, with no gain for a public project.

## Consequences
- A fresh install pulls about six third-party images from Docker Hub. Behind a shared NAT (offices, CI) the
  anonymous limit can block an install. The doctor checks registry reachability (R10), and failures name the
  limit. CI caches the images.
- The release workflow ends with a logged-out pull of every published digest and fails when access is denied
  (R52).
- Publishing under another owner means changing E4 before the run. The built-in signer identity changes with it
  (ADR 0060).
