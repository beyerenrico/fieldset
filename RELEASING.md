# Releasing form-schema-app

First version (plan U7, M9). The release checklist and the agent guide are completed in M13 (U21).

Publishing is a human step (BUILD_PROMPT_V2 E18, ADR 0064). The autonomous build never pushes a `v*` tag and
never publishes to GHCR or GitHub Releases. It builds and tests every release locally with the harness
described below.

## What a release is

- Multi-arch app image `ghcr.io/beyerenrico/form-schema-app` (linux/amd64, linux/arm64), with SBOM and
  provenance attestations, signed keylessly with cosign. The index and every manifest in it are signed.
- Release assets on the GitHub Release of the tag:
  - `install.sh` and its bundle `install.sh.sigstore.json`;
  - `release-manifest.json` and its bundle `release-manifest.json.sigstore.json`;
  - `shim.sh`, the stack file and the agent guide, once they exist;
  - `manifest.schema.json` and `SHA256SUMS`.
- The release manifest pins the app image and every third-party image (Postgres, Valkey, Gotenberg, Mailpit,
  Caddy, cloudflared) by digest, with sizes per platform. It also lists the asset checksums, the release-notes
  URL and the oldest version the release upgrades from. Third-party pins live in `distribution/release.json`
  and change only by a deliberate commit (R4).
- A stable tag `vX.Y.Z` publishes the image tags `X.Y.Z`, `X.Y`, `X` and `latest`. A pre-release tag
  (`vX.Y.Z-alpha.N`, `-beta.N`, `-rc.N`) publishes only `X.Y.Z-…`. It is marked as a pre-release and never
  becomes the latest GitHub Release. `php artisan release:version <tag>` prints the tags for any version.

### Installing a release

`releases/latest/download/` resolves to the newest stable GitHub Release. Pre-releases are published with
`--prerelease --latest=false` (`release.yml`), so a 2.x `latest` only exists from 2.0.0 on. This one-liner works
only for stable releases:

```sh
curl --proto '=https' --tlsv1.2 -fsSL https://github.com/beyerenrico/form-schema-app/releases/latest/download/install.sh | sh -s -- [flags]
```

For a pre-release (and for every release before 2.0.0), name the version twice: in the download path, and as
`--version`, which makes `install.sh` fetch the manifest from `download/v<version>/` instead of `latest/download/`:

```sh
curl --proto '=https' --tlsv1.2 -fsSL https://github.com/beyerenrico/form-schema-app/releases/download/v<ver>/install.sh | sh -s -- --version <ver> [flags]
```

For example `<ver>` = `2.0.0-alpha.1`.

### The signer identity

`install.sh` and the image verify the manifest against an identity that is built into them, never read from
the manifest (R58, ADR 0060):

- certificate identity, an anchored regular expression for
  `https://github.com/beyerenrico/form-schema-app/.github/workflows/release.yml@refs/tags/v<semver>`
  (the exact expression is `CERTIFICATE_IDENTITY_REGEXP` in `.github/workflows/release.yml`);
- OIDC issuer `https://token.actions.githubusercontent.com`;
- workflow trigger `push`.

Renaming the workflow file, the repository or the owner changes the identity. Every existing `install.sh` and
image would then reject new releases.

## One-time repository setup

1. **Protect `v*` tags.** Add a tag ruleset for `v*` that restricts creation, update and deletion to the
   maintainers. Anyone who can push a `v*` tag can publish a signed release.
2. **Create the `release` environment.** Restrict deployments to tags matching `v*` (Settings → Environments →
   release → Deployment branches and tags). Required reviewers are optional. Every job that pushes, signs or
   publishes, plus the manifest job (image, manifest, sign, tag, publish, finalize), uses the environment, so with
   required reviewers each of them would wait for an approval.
3. **Workflow permissions.** The workflow sets `permissions: {}` and grants each job only what it needs:
   `packages: write` and `id-token: write` to push and sign, `contents: write` to publish. Repository
   settings must allow these (Settings → Actions → General → Workflow permissions).
4. **GHCR package visibility.** The first push creates the package `form-schema-app` as private. Anonymous
   pulls need it public. **Once a package is public, it cannot be made private again** (GitHub docs, ADR 0050).
   Make it public only when the first release candidate has passed the checks below.

## Publishing a release

1. Make sure `main` holds the release: milestone gate passed, `CHANGELOG.md` updated, and
   `distribution/install.sh` and `distribution/shim.sh` present. The workflow refuses to publish without them.
2. Tag and push:

   ```sh
   git tag -s v2.0.0-alpha.1 -m "form-schema-app 2.0.0-alpha.1"
   git push origin v2.0.0-alpha.1
   ```

   The **first public publish must be a pre-release** (for example `v2.0.0-alpha.1`), so `latest` does not move
   (E18).
3. Watch the `Release` workflow. It runs these jobs:

   | Job | What it does |
   |---|---|
   | prepare | validates the tag (`release:version`) and computes the image tags |
   | image | buildx multi-arch build with `--sbom=true --provenance=mode=max`, pushed by digest only; `cosign sign --recursive` (keyless); verifies the identity |
   | manifest | `php artisan release:manifest` (every image by digest) with production dependencies only (`composer install --no-dev --no-scripts --no-plugins`); refuses a harness build of `install.sh`; **no OIDC token** |
   | sign | runs no PHP or Composer: checks that the manifest's `images.app` digest is the one the image job pushed and that every file equals the tagged commit's and its checksum in the manifest, then `cosign sign-blob --bundle` for the manifest and `install.sh` (keyless) and writes `SHA256SUMS` |
   | scan | Trivy on the app image (no exceptions) and every third-party digest (only its own exception file, ADR 0071) on both platforms; a fixable critical or high finding fails the release |
   | tag | points the release's image tags at the scanned digest, and checks that the digest is unchanged |
   | publish | creates a **draft** GitHub Release with every asset |
   | verify-public | pulls every manifest digest on both platforms **without credentials** |
   | finalize | takes the release out of draft, then downloads the manifest anonymously and compares it |

4. **First release only:** `verify-public` fails while the GHCR package is still private. Make the package
   public (Package settings → Danger zone → Change visibility; irreversible), then re-run the failed jobs. The
   release stays a draft until then, so nobody can install a release that is not anonymously pullable.
5. Run the post-publish checklist below.

When `scan` finds a fixable critical or high vulnerability, nothing is tagged or published. The image exists in
GHCR only by digest. Fix it (base image, Go toolchain or source pin in `docker/app/Dockerfile`, or third-party pin
in `distribution/release.json`), delete the tag, and tag a new version. Do not move a published tag.

Scan policy (ADR 0071): the app image allows no exception. A third-party image may carry dated exceptions in
`distribution/trivy/<service>.trivyignore.yaml`, only for findings that no released upstream image fixes. Each
entry is scoped to one component and has a statement and an `expired_at` at most 90 days out. Before tagging,
check the dates: an expired entry fails `scan`. Refresh the pin when a fixed image exists; otherwise renew the
entry in a reviewed commit with a current statement. The `scan` log lists every suppressed finding.

## Building and testing a release locally (harness)

The harness (KTD23, ADR 0064, ADR 0067) runs without any account. It uses a `registry:3`, a static release
server for `dist/`, a buildx builder on `network=host` and a cosign key pair generated per run.

```sh
vendor/bin/pest --testsuite=Install --filter=HarnessRelease           # build, sign, serve and check a release
FSA_TEST_PLATFORM=linux/amd64 vendor/bin/pest --testsuite=Install --filter=HarnessRelease   # emulated amd64
FSA_HARNESS_PLATFORMS=linux/amd64,linux/arm64 vendor/bin/pest --testsuite=Install --filter=HarnessRelease
```

`tests/Support/Docker/ReleaseHarness.php` drives it. `ReleaseHarness::start()` brings up the compose project
`fsa-test-harness-<id>` from `tests/Install/Harness/compose.harness.yaml`. `publish($version)` then runs the
steps of the workflow:

1. build and push the app image with SBOM and provenance;
2. sign it with the local key;
3. mirror every third-party pin by digest;
4. run `release:manifest` and `cosign sign-blob --bundle`;
5. lay out `dist/download/v<version>/`, plus `dist/latest/download/` for stable versions only.

`stop()` removes the containers, the network, the volume, the builder and the run directory.

- Registry: `ReleaseHarness::registry()` (`127.0.0.1:<port>`) works for the host, the Docker daemon and
  host-network containers. Installs pull `<registry>/<repository>@<digest>` there (`FSA_REGISTRY`).
- Release server: `ReleaseHarness::releaseUrl()` stands in for
  `https://github.com/beyerenrico/form-schema-app/releases` (`FSA_RELEASE_URL`).
- Local public key: `$harness->cosign->publicKey()`. Only harness builds accept it (`FSA_TRUST_KEY`, U4).

Leftovers from an interrupted run (from the repository root). The compose file requires `FSA_HARNESS_DIST`,
which `ReleaseHarness` sets to the run's `dist/`; give it as an absolute path, because Compose reads a relative
one as a volume name or resolves it against `tests/Install/Harness/`:

```sh
FSA_HARNESS_DIST="$PWD/storage/fsa-test-harness/<id>/dist" docker compose -p fsa-test-harness-<id> \
  -f tests/Install/Harness/compose.harness.yaml --profile forwarder down -v
docker buildx rm fsa-test-harness-<id>
rm -rf storage/fsa-test-harness/<id>
```

Mirrored blobs and the build cache stay in `storage/fsa-test-cache/`; delete that directory to reclaim the disk
space.

Lint locally like CI:

```sh
docker run --rm -v "$PWD:/repo:ro" -w /repo rhysd/actionlint:1.7.12 -color
docker run --rm -i hadolint/hadolint:v2.15.1 hadolint - < docker/app/Dockerfile
docker run --rm -v "$PWD:/mnt:ro" -w /mnt koalaman/shellcheck:v0.11.0 docker/app/entrypoint.sh docker/app/fsa distribution/*.sh
```

## Post-publish checklist

None of these checks can run inside the autonomous build (§15.1). All of them are **not yet verified**.

- [ ] GHCR package `form-schema-app` made public (irreversible), after the first pre-release passed review.
      Not yet verified.
- [ ] Every digest in `release-manifest.json` pulls anonymously on amd64 and arm64 (`verify-public` is green).
      Not yet verified.
- [ ] `cosign verify` with the pinned identity passes, run from a machine outside CI:

  ```sh
  cosign verify ghcr.io/beyerenrico/form-schema-app@<digest> \
    --certificate-identity-regexp '<CERTIFICATE_IDENTITY_REGEXP from release.yml>' \
    --certificate-oidc-issuer https://token.actions.githubusercontent.com \
    --certificate-github-workflow-trigger push
  cosign verify-blob release-manifest.json --bundle release-manifest.json.sigstore.json \
    --certificate-identity-regexp '<same>' --certificate-oidc-issuer https://token.actions.githubusercontent.com \
    --certificate-github-workflow-trigger push
  ```

  These are the flags `install.sh` (`fsa_install_trust()`) and `fsa` (`SignerIdentity`) verify the manifest with
  (ADR 0070).

  Not yet verified.
- [ ] One-liner install on a throwaway VPS in domain mode against Let's Encrypt staging (a pre-release needs the
      `download/v<ver>` form with `--version`, see [Installing a release](#installing-a-release)). Not yet verified.
- [ ] A real cloudflared quick tunnel (`fsa tunnel start`) reached from a phone. Not yet verified.
- [ ] The local CA trusted on one iOS device and one Android device, and the Field App installs and syncs.
      Not yet verified.
