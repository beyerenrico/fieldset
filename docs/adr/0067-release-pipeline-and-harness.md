# 0067: Release pipeline, release manifest and local harness (as built)
- Status: accepted
- Date: 2026-10-18

## Context
Plan U7 (M9-T06) implements KTD22 (release workflow), KTD23 (install journeys against a harness), R1–R4, R8,
E14 and E18, building on ADR 0050 (GHCR and GitHub Releases), ADR 0060 (signatures, manifest), ADR 0064 (local
harness, human publish) and ADR 0065 (image). U4 (`install.sh`, manifest verification) and U5 (J1 install) build
on what this ADR fixes: the manifest format, the asset layout, the signer identity and the harness API.

## Decision

**Manifest.** `distribution/manifest.schema.json` (JSON Schema 2020-12), validated with opis/json-schema through
the SDK's `MetaSchemaValidator`, so no new dependency is added.
- Top-level keys: `schema_version` (1), `version`, `channel` (`stable|alpha|beta|rc`, derived from the version),
  `notes_url`, `upgrades_from`, `platforms`, `images` and `assets`.
- Each `images.<service>` entry has `registry`, `repository`, `tag`, `digest` (the index) and
  `platforms.<os/arch>` = `{digest, compressed_size}`. The required services are `app`, `postgres`, `valkey`,
  `gotenberg`, `mailpit`, `caddy` and `cloudflared`.
- Each `assets.<file name>` entry is `{sha256, size}`.
- The manifest holds no signer identity. Readers ignore unknown keys (KTD6); an incompatible change raises
  `schema_version`.
- An install pulls `<registry or FSA_REGISTRY>/<repository>@<digest>`. The tag is informational.

**Release inputs.** `distribution/release.json` is committed and changes only by a deliberate commit (R4). It
holds:
- the app repository;
- the release-notes URL template;
- `upgrades_from` (`2.0.0-alpha.1`, the first 2.x release);
- the third-party pins: `postgres:16.15-alpine`, `valkey/valkey:8.1.10-alpine`,
  `gotenberg/gotenberg:8.38.1-chromium`, `axllent/mailpit:v1.31.6`, `caddy:2.11.7-alpine` and
  `cloudflare/cloudflared:2026.10.1`.

Each pin is an index digest verified on 2026-10-18 with `docker buildx imagetools inspect`. Every pin has
linux/amd64 and linux/arm64. The Caddy, Mailpit and cloudflared versions are those of ADR 0052, 0054 and 0057.
Postgres stays on the alpine libc family (KTD20), and Valkey stays on the dev stack's major (8).

**Generator.** `php artisan release:manifest <version> --app-digest=… [--platform=…] [--asset=…] [--mirror=…]
[--output=…]`, plus `release:version <tag>`, which prints the channel and image tags as JSON.
- Domain code is in `app/Domain/Instance/Release/`.
- The commands are release tooling, not `fsa:*` commands, because they need no instance directory. They live in
  `app/Domain/Instance/Release/Console` and are registered in `bootstrap/app.php`. They cannot sit in
  `app/Console/Commands`, because the arch test forbids `App\Console` from using `App\Domain\Instance\Release`.
- The generator reads every image index and platform manifest over the registry API. A missing digest, a
  missing platform or a non-index fails generation. It records `compressed_size` (config plus layers) per
  platform and validates the result against the schema.
- GHCR credentials before the package is public come from `RELEASE_REGISTRY_USERNAME` and
  `RELEASE_REGISTRY_PASSWORD`, sent only to the app image's registry token service.
- The version grammar is `X.Y.Z` or `X.Y.Z-(alpha|beta|rc)[.id…]` (semver without build metadata). Semver
  precedence is implemented; `upgrades_from` may not exceed the version.

**Workflow** (`.github/workflows/release.yml`). It runs on `push` of `v*` tags, sets `permissions: {}` and
grants each job only what it needs. The jobs that write use `environment: release`. The jobs run in this order:
1. prepare: `release:version`.
2. image: QEMU and buildx for `linux/amd64,linux/arm64` with `sbom: true`, `provenance: mode=max` and
   `no-cache` (a release reuses no cache another workflow wrote). The image is pushed **by digest only**, then
   signed with `cosign sign --yes --recursive` (keyless, cosign v3.1.3), and the signature is checked against the
   pinned identity.
3. manifest: `release:manifest`, then `cosign sign-blob --bundle` for `release-manifest.json` and `install.sh`
   (bundles named `<file>.sigstore.json`, verified right away), then `SHA256SUMS`.
4. scan: Trivy 0.76.0 on every manifest image and platform, `--severity CRITICAL,HIGH --ignore-unfixed
   --exit-code 1`. GHCR credentials come from the Docker config, so the token never goes to Docker Hub.
5. tag: `docker buildx imagetools create` points the tags at the scanned digest, and the workflow checks that
   the digest is unchanged. Retagging a single index keeps its digest (verified against a local `registry:3`).
6. publish: `gh release create --draft --verify-tag` with every asset; pre-releases also get `--prerelease
   --latest=false`.
7. verify-public: a logged-out `docker pull` of every digest on both platforms, with no token permissions.
8. finalize: undraft the release (`--latest` for stable releases only), then download the manifest anonymously
   and compare it.

A vulnerable image is therefore never tagged, and an image that cannot be pulled anonymously is never visible as
a release. The first release waits in draft until a human makes the package public (RELEASING.md).

| Action (pinned by commit SHA) | Version | SHA |
|---|---|---|
| actions/checkout | v7.0.1 | `3d3c42e5aac5ba805825da76410c181273ba90b1` |
| shivammathur/setup-php | 2.40.0 | `eb7c497e18156a6bbabfef1d3a82760b9eda3962` |
| docker/setup-qemu-action | v4.4.0 | `99012661954931238ded8c8b007157a8430204e1` |
| docker/setup-buildx-action | v4.4.1 | `f87e5991a6d7451dcb8d9637bfbc97413f497069` |
| docker/login-action | v4.6.0 | `dbcb813823bdd20940b903addbd779551569679f` |
| docker/build-push-action | v7.4.0 | `c3c9e263c25d99ce0380d002d59b67737d91b0dc` |
| sigstore/cosign-installer | v4.1.2 | `6f9f17788090df1f26f669e9d70d6ae9567deba6` |
| actions/upload-artifact | v7.0.2 | `cf430e030ddbb5b0abf93d22962f4752f3646cd9` |
| actions/download-artifact | v8.0.2 | `9000827ccba6bdab643e8b6fd33ac0654aef8333` |
| actions/cache (CI) | v6.1.0 | `55cc8345863c7cc4c66a329aec7e433d2d1c52a9` |

Versions are the latest GitHub releases on 2026-10-18; SHAs come from `git ls-remote` (setup-php's tag is
annotated, so the SHA is that of the commit it points to). The linter images are pinned by digest:
`rhysd/actionlint:1.7.12`, `hadolint/hadolint:v2.15.1`, `koalaman/shellcheck:v0.11.0` and
`aquasec/trivy:0.76.0`. `tests/Unit/Release/ReleaseWorkflowTest.php` keeps the SHA pins, digest pins, the
trigger and the identity in place.

**Signer identity** (for U4's `install.sh` and the image): `--certificate-identity-regexp
'^https://github\.com/beyerenrico/form-schema-app/\.github/workflows/release\.yml@refs/tags/v(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)(-(alpha|beta|rc)(\.(0|[1-9][0-9]*|[0-9]*[A-Za-z-][0-9A-Za-z-]*))*)?$'`
together with `--certificate-oidc-issuer https://token.actions.githubusercontent.com` and
`--certificate-github-workflow-trigger push`.

**Assets and layout.** The GitHub Release holds `install.sh`, `install.sh.sigstore.json`, `release-manifest.json`,
`release-manifest.json.sigstore.json`, `shim.sh`, the stack file and agent guide when present,
`manifest.schema.json` and `SHA256SUMS`.
- The default `FSA_RELEASE_URL` is `https://github.com/beyerenrico/form-schema-app/releases`. Assets are at
  `<url>/download/v<version>/<file>`, and for the newest stable release at `<url>/latest/download/<file>`.
- The harness serves the same paths from `dist/`.

**Harness** (`tests/Install/Harness/compose.harness.yaml`, `tests/Support/Docker/{ReleaseHarness,
HarnessRelease,Cosign,OciRegistry}.php`, journey `tests/Install/Journeys/HarnessReleaseTest.php`):
- Compose project `fsa-test-harness-<id>`:
  - `registry:3.1.2` with `REGISTRY_VALIDATION_MANIFESTS_INDEXES_PLATFORMS=none` (partial indexes) and relative
    URLs;
  - the Caddy 2.11.7 pin as a file server for `dist/`;
  - both published on random loopback ports.
- **Docker Desktop:** published ports exist only on the Mac side. Neither the daemon nor `network=host`
  containers reach `127.0.0.1:<port>` inside the VM. Measured with Docker Desktop 4.63, gvisor networking: the
  pull timed out or was refused, also with a `0.0.0.0` or `[::1]` binding. A `registry-forwarder` profile (Caddy
  `reverse-proxy` in the VM's host network, same port, to the registry container's address) starts only when a
  host-network probe fails. On Linux the published port already works. The registry is then `127.0.0.1:<port>`
  for the host, the daemon, the builder and cosign alike.
- **Build:** `docker buildx create --driver docker-container --driver-opt network=host` (`buildkitd.toml` is not
  needed, because BuildKit uses plain HTTP for loopback registries). The build runs with `--sbom=true
  --provenance=mode=max`, tagged with `ReleaseVersion::imageTags()`, and uses a local build cache in
  `storage/fsa-test-cache/buildx`.
- **Third-party images:** a small OCI client copies the exact upstream index bytes, plus only the platforms
  under test, into the registry. The install pulls the upstream digest, while blobs are downloaded once into
  `storage/fsa-test-cache/oci` (content-addressed, verified). Re-runs make no Docker Hub requests.
- **Signing:** a key pair generated per run with the official cosign image pinned by digest. `sign-blob` runs
  with an empty signing config (no Rekor or TSA), `verify-blob` with `--insecure-ignore-tlog`, both with
  `--network none`. The app image is also signed recursively with the local key.
- `stop()` removes the project with its volume, the builder (`buildx rm`), the references the test pulled and
  the run directory `storage/fsa-test-harness/<id>`.

**CI** (`.github/workflows/ci.yml`, existing jobs unchanged): `shellcheck` (`docker/app/entrypoint.sh`,
`docker/app/fsa`, and `distribution/*.sh` once they exist), `hadolint` (with the Dockerfile's inline ignores),
`actionlint`, `image-scan` (Trivy on the CI-built image) and `install-journeys`. The last runs `vendor/bin/pest
--testsuite=Install` with `FSA_REQUIRE_DOCKER=1` on amd64, only for pushes to `main` and `m9-*`…`m13-*`, and
caches `storage/fsa-test-cache`.

## Alternatives considered
- **Command in `app/Console/Commands`:** violates the instance boundary arch test.
- **Scan after tagging:** a vulnerable digest would already carry `latest`.
- **Publish the release directly:** the first release would be visible before the package can be pulled
  anonymously.
- **Mirror third-party images with `docker push` or `imagetools create`:** the classic image store re-compresses
  layers, which changes the digests. A full index copy downloads every platform (about 2.5 GB, plus Docker Hub
  pull quota) on every run.
- **Publish the registry on `0.0.0.0`:** it does not help on Docker Desktop, and it exposes the registry to the
  LAN.
- **Fixed registry port with `buildkitd.toml`:** collides when several harnesses run in parallel.
- **Rekor or keyless signing in the harness:** needs Sigstore's public services and an OIDC identity (§15.1).

## Consequences
- U4 builds the identity above into `install.sh` and the image, verifies `release-manifest.json.sigstore.json`,
  and accepts `FSA_TRUST_KEY` only in harness builds. `ManifestSchema` and `ReleaseManifest::fromJson()` are
  ready for reading manifests. The image does not ship `distribution/manifest.schema.json` yet; `fsa` needs it
  in the image to validate manifests (U4).
- The schema does not require `install.sh` among the assets yet, because U4 creates it; the workflow already
  refuses to release without it.
- On arm64 (Docker Desktop 4.63), a first harness run took 4 min 21 s: build, mirror (about 700 MB, mostly
  Gotenberg) and checks. Runs with a warm `storage/fsa-test-cache` (about 2 GB) took 2 min 32 s.
  BuildKit prints harmless `ref layer-… locked` retries while it exports the local cache.
- **The scan gate fails today.** Trivy 0.76.0 ran on 2026-10-18 with `--severity CRITICAL,HIGH
  --ignore-unfixed`, amd64 for the pins. Every finding sits in vendor binaries or base packages that no newer
  upstream release fixes yet; cosign 3.1.3, Compose 5.6.0, age 1.3.2 and all six pins are the latest releases.
  - App image (U1 build): 34 HIGH in the Go binaries cosign, Compose, age, age-keygen and age-plugin-batchpass
    (Go stdlib and x/* modules); none in Debian or Composer packages.
  - postgres: 1 CRITICAL and 24 HIGH, all in `gosu` (Go stdlib).
  - gotenberg: 87 HIGH (the chromium Debian packages, plus pdfcpu and gotenberg Go modules).
  - caddy: 4 HIGH (Go stdlib, x/net).
  - cloudflared: 1 HIGH (libssl3t64).
  - valkey and mailpit: clean.

  CI `image-scan` is therefore red, and a tagged release would stop at `scan` before anything is tagged or
  published. Accepting findings (`.trivyignore` with reasons and expiry) or narrowing the policy is a human
  decision (QUESTIONS); the gate is not lowered here.
- Unverified until a human publishes (ADR 0064): keyless signing in Actions, the `release` environment rules
  and GHCR anonymous pulls.
