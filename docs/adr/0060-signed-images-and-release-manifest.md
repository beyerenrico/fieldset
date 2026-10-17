# 0060: Signed multi-arch images, attestations and a signed release manifest
- Status: accepted
- Date: 2026-10-17

## Context
E14. BUILD_PROMPT_V2 left "Manifest signature verification vs cosign in the image" open: a same-origin checksum
cannot protect a manifest whose digests decide which image gets the socket. Plan R58 and KTD22 resolve it.

## Decision
- The release workflow runs on protected `v*` tags (trigger `push`) in a release environment. buildx builds both
  architectures with `--sbom=true --provenance=mode=max` and pushes. cosign v3 signs every image by digest,
  recursively and keyless, and `sign-blob --bundle` signs the manifest and `install.sh`. Trivy scans the app image
  and every third-party digest. The assets are published, then the digests are pulled anonymously.
- The manifest pins every image by digest (app, Postgres, Valkey, Gotenberg, Caddy, Mailpit, cloudflared) and
  lists asset checksums, the notes URL and the oldest upgrade source (R2, R4). It carries no signer identity.
- Install and upgrade verify the manifest signature before using any digest (R58).
  - The identity is built into `install.sh` and the image, never read from the manifest: an anchored
    `--certificate-identity-regexp` for
    `https://github.com/beyerenrico/form-schema-app/.github/workflows/release.yml@refs/tags/v<semver>`, and the
    issuer `https://token.actions.githubusercontent.com`.
  - `install.sh` uses the official cosign image, pinned by digest, and `fsa` uses the cosign binary in the image.
  - Only harness builds accept a local key (`FSA_TRUST_KEY`, build argument). A production build exits 2 when it
    is set, and there is no skip switch. An unreachable Sigstore (TUF, Rekor) means exit 3 with a hint.

Verified 2026-10-17:
- cosign is pinned at 3.1.x. 3.1.3 (2026-08-06) is current; `cosign-linux-amd64` is 134 MiB and `-arm64` 126 MiB,
  and `ghcr.io/sigstore/cosign/cosign:v3.1.3` exists. License: Apache-2.0.
- buildx 0.38.0 (Apache-2.0). Provenance `mode=min` is the default and SBOM is opt-in. Attestations need a push,
  the docker-container driver or the containerd image store (docs.docker.com/build/metadata/attestations).
- GitHub's OIDC issuer is `https://token.actions.githubusercontent.com`, and `job_workflow_ref` reads
  `owner/repo/.github/workflows/<file>@<ref>` (docs.github.com, OIDC). `id-token: write` is unverified here.
- Trivy 0.76.0 (Apache-2.0) fails the release on any critical or high finding with an available fix (§10).
- Licenses (§5 column, from GitHub metadata): Docker CLI, Compose and cosign are Apache-2.0; age and batchpass
  BSD-3-Clause; `postgresql-client-16` uses the PostgreSQL License, which §5 does not list. The license check
  (§10) is extended to these copied binaries, with SPDX `PostgreSQL` added to the allow-list
  (`scripts/check-licenses.php` checks only Composer today). Caddy (Apache-2.0), Mailpit (MIT) and cloudflared
  (Apache-2.0) run as pinned services and are not copied into the image.

## Alternatives considered
- **Unsigned images:** users and agents cannot check what they run. **Checksum-only manifest:** a swapped
  manifest hands the socket, and root, to any image. **Identity in the manifest:** self-certifying.

## Consequences
- cosign takes about 130 MB of the E17 budget (ADR 0063). A Go standard-library CVE in the Docker CLI, Compose or
  cosign can block a release. A new owner, repository or workflow path needs a new `install.sh` and image.
