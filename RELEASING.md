# Releasing Fieldset

Written in M9 (plan U7), completed in M13 (U21) with the agent guide and the post-publish checklist.

Publishing is a human step (BUILD_PROMPT_V2 E18, ADR 0064). The autonomous build never pushes a `v*` tag and
never publishes to GHCR or GitHub Releases. It builds and tests every release locally with the harness
described below.

## What a release is

- Multi-arch app image `ghcr.io/beyerenrico/fieldset` (linux/amd64, linux/arm64), with SBOM and
  provenance attestations, signed keylessly with cosign. The index and every manifest in it are signed.
- Release assets on the GitHub Release of the tag:
  - `install.sh` and its bundle `install.sh.sigstore.json`;
  - `release-manifest.json` and its bundle `release-manifest.json.sigstore.json`;
  - `shim.sh`;
  - `compose.stack.yaml`, the stack file for running without `fieldset` (R18, ADR 0076). It is generated for every
    release from the manifest's pins, so it is not a file in the repository;
  - `agent-guide.md`, the agent guide (`docs/guide/agent-guide.md`; the manifest job adds it whenever the file
    exists, and the sign job checks it against the tagged commit);
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
`--prerelease --latest=false` (`release.yml`), so a 2.x `latest` only exists from 2.1.0 on (2.0.0 was never published). This one-liner works
only for stable releases:

```sh
curl --proto '=https' --tlsv1.2 -fsSL https://github.com/beyerenrico/fieldset/releases/latest/download/install.sh | sh -s -- [flags]
```

For a pre-release (and for every release before 2.1.0), name the version twice: in the download path, and as
`--version`, which makes `install.sh` fetch the manifest from `download/v<version>/` instead of `latest/download/`:

```sh
curl --proto '=https' --tlsv1.2 -fsSL https://github.com/beyerenrico/fieldset/releases/download/v<ver>/install.sh | sh -s -- --version <ver> [flags]
```

For example `<ver>` = `2.1.0-alpha.1`.

### The signer identity

`install.sh` and the image verify the manifest against an identity that is built into them, never read from
the manifest (R58, ADR 0060):

- certificate identity, an anchored regular expression for
  `https://github.com/beyerenrico/fieldset/.github/workflows/release.yml@refs/tags/v<semver>`
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
4. **GHCR package visibility.** The first push creates the package `fieldset` as private. Anonymous
   pulls need it public. **Once a package is public, it cannot be made private again** (GitHub docs, ADR 0050).
   Make it public only when the first release candidate has passed the checks below.

## Pre-publish checklist

Human steps before the first tag push of 2.1.0, the first release under the name Fieldset. The autonomous build
cannot do them.

- [ ] **Trademark check done** for the name "Fieldset" (product, CLI `fieldset`, Fieldset Spec 1.0).
- [ ] **GitHub repository renamed to `beyerenrico/fieldset` before any tag push.** The signer identity built into
      `install.sh`, the image and `release.yml` names that repository (see [The signer identity](#the-signer-identity)).
      A tag pushed before the rename is signed under the old repository's identity, and every installer rejects
      the release.
- [ ] **Push only the `v2.1.0` tag, by name** (`git push origin v2.1.0`; for the first public publish, the
      `v2.1.0-…` pre-release tag of step 2 below, also by name). Never `git push --tags`: it would also push the
      local `v2.0.0` tag, which was never published, and every milestone and test tag.

## Publishing a release

1. Make sure `main` holds the release: milestone gate passed, `CHANGELOG.md` updated (the version's entry with its
   date), and `distribution/install.sh` and `distribution/shim.sh` present. The workflow refuses to publish without
   `install.sh` and `shim.sh` (it does not check the changelog). The `Install journeys` workflow should be green on
   the release commit: it runs every night on `main`, or start it by hand (Actions → Install journeys → Run workflow).
   The Release workflow runs the journeys again on amd64 and builds nothing until they pass. Before tagging, also run
   locally:
   - the install journeys on arm64 (Apple Silicon), J11 (the agent guide's doc-test) among them:
     `vendor/bin/pest --testsuite=Install` (see [the harness](#building-and-testing-a-release-locally-harness));
   - the guide checks in the fast suite (`tests/Unit/Docs`): every `fieldset` command, flag, environment variable and
     link the guides name exists (ADR 0042), and no raw HTML tag or `<placeholder>` stands outside code (ADR 0094);
   - the Trivy exception dates (see below);
   - `fieldset_shim_version` in `distribution/shim.sh`: raise it whenever the release changes the shim, or upgraded
     instances never get the new script (ADR 0095). `tests/Unit/Distribution/LauncherVersionTest.php` pins the shim's
     SHA-256 per version and fails until the version and the pinned hash are updated together;
   - for a 2.1.x final: the [WSL checklist](#wsl-checklist-21x-release-candidates) passed on the release candidate it
     is tagged from.
2. Tag and push:

   ```sh
   git tag -s v2.1.0-alpha.1 -m "fieldset 2.1.0-alpha.1"
   git push origin v2.1.0-alpha.1
   ```

   The **first public publish must be a pre-release** (for example `v2.1.0-alpha.1`), so `latest` does not move
   (E18).
3. Watch the `Release` workflow. It runs these jobs:

   | Job | What it does |
   |---|---|
   | prepare | validates the tag (`release:version`) and computes the image tags |
   | journeys | the whole Install suite on amd64 against the tagged commit (`install-journeys.yml`, about 2 h 10 m, ADR 0091); the image job waits for it, so a failed journey stops the release before anything is built |
   | image | buildx multi-arch build with `--sbom=true --provenance=mode=max`, pushed by digest only; `cosign sign --recursive` (keyless); verifies the identity |
   | manifest | `php artisan release:manifest --stack-file=dist/compose.stack.yaml` (every image by digest; also renders the stack file from the same pins and lists it as an asset) with production dependencies only (`composer install --no-dev --no-scripts --no-plugins`); refuses a harness build of `install.sh`; then the R39 check `php artisan release:check-pg-dump` (the app image's `pg_dump` major must not be below the manifest's Postgres major, run on both platforms, arm64 under QEMU, after a read-only GHCR login; ADR 0091); **no OIDC token** |
   | sign | runs no PHP or Composer: checks that the manifest's `images.app` digest is the one the image job pushed, that every file except the manifest and `compose.stack.yaml` equals the tagged commit's, and that every asset the manifest lists matches its checksum there. The manifest must list `install.sh`, `shim.sh` and `compose.stack.yaml`; every `image:` line of the stack file must be a manifest pin in the form `${FIELDSET_REGISTRY:-<registry>}/<repository>@<digest>`, and the stack file must not mount the Docker socket. Then `cosign sign-blob --bundle` for the manifest and `install.sh` (keyless) and writes `SHA256SUMS` |
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

## WSL checklist (2.1.x release candidates)

Windows through WSL 2 is tested by hand, not in CI (ADR 0051 as amended by ADR 0095). The human runs this list on a
Windows PC for every 2.1.x release candidate before the final is tagged. Any failure means a fix on a branch and a new
release candidate; the final waits for a run without failures. Record each run as evidence in
`docs/agent/milestones/evidence/R2.1.1/` (for 2.1.2: `R2.1.2/`): one file per run, `wsl-checklist-<ver>.md`, with the
Windows build, the Docker Desktop or Engine version, and pass or fail with the output of each failed step. Remove host
names and anything else private first.

**First: the fact-finding script** (plan U1). Run it in each distro before the checks below, and keep its output with the
evidence. Take it from the tag, so it matches the release:

```sh
curl --proto '=https' --tlsv1.2 -fsSLO https://raw.githubusercontent.com/beyerenrico/fieldset/v<ver>/scripts/wsl/facts.sh
FIELDSET_FACTS_IMAGE=ghcr.io/beyerenrico/fieldset:<ver> sh facts.sh > wsl-facts-desktop.txt   # in the Docker Desktop distro
FIELDSET_FACTS_IMAGE=ghcr.io/beyerenrico/fieldset:<ver> sh facts.sh > wsl-facts-engine.txt    # in the Docker Engine distro
```

It is read-only: it changes no Windows or Docker setting and removes the containers and the temporary directory it
creates. Paste the outputs into `docs/agent/milestones/evidence/R2.1.1/wsl-facts-desktop.txt` and `wsl-facts-engine.txt`.
They confirm or refute the Windows facts still open in QUESTIONS 81.

**Full run on Docker Desktop** (its WSL integration on for the distro), with the release candidate's one-liner
(`download/v<ver>/install.sh` with `--version <ver>`, see [Installing a release](#installing-a-release)):

- [ ] Install into `~/fieldset`: exit 0; the owner's sign-in link opens in the Windows browser and is printed in the
      terminal. If a failed 2.1.0 install left that directory behind, keep it: the install must complete there (R24).
- [ ] The browser keeps a `#fragment`: in the distro, run
      `powershell.exe -NoProfile -Command "Start-Process 'http://localhost:<port>/admin#fragment-check'"`; the address
      bar shows `#fragment-check`.
- [ ] `fieldset status` and `fieldset doctor`: no failure; `permissions` passes with the measured owner (your UID,
      `owner_source` `measured`), `wsl_lifecycle` passes as information.
- [ ] `fieldset backup`, then `fieldset restore <backup> --drill`.
- [ ] Upgrade: from rc.2 on, an instance of the previous release candidate upgrades with `fieldset upgrade --to <ver>`
      (the next command prints the script refresh line when the shim changed), then `fieldset rollback --yes` and the
      upgrade again. Skip it for rc.1: no earlier release installs on WSL.
- [ ] Phone over Wi-Fi: `fieldset config set lan-address <Windows LAN IPv4> --yes` (only if the detected address is
      wrong) and `fieldset config set phone-https local-ca --yes`; `fieldset doctor` shows `windows_network`, run the
      PowerShell lines it prints as administrator, `fieldset doctor` again passes; the phone opens the trust page,
      trusts the CA, installs the Field App, works offline and syncs.
- [ ] A domain-mode install (`--domain` with `--acme-staging`, the router forwarding 80 and 443 to the PC) or a
      tunnel-mode install (`--phone-https tunnel`) in a second directory, reached from a phone on mobile data (R10).
- [ ] Close every WSL terminal, wait 2 minutes, then open the app in the Windows browser: it still loads. Record the
      result: it decides the Docker Desktop idle-distro fact.
- [ ] `fieldset uninstall --keep-backups` prints the Windows cleanup lines (warning `windows_rules`); after running
      them, `Get-NetFirewallRule -Name fieldset-*` in PowerShell finds nothing.

**Short run on Docker Engine** in a second distro (Docker Desktop's WSL integration off for it; systemd on and
`instanceIdleTimeout=-1` as the install guide says):

- [ ] Install with the one-liner: exit 0, and the app opens in the Windows browser.
- [ ] `fieldset status` and `fieldset doctor`: no failure; `wsl_lifecycle` passes.
- [ ] A phone: `fieldset config set phone-https local-ca --yes`, run the portproxy and firewall lines `fieldset doctor`
      prints, and the phone opens the app over Wi-Fi.

**2.1.2 smoke run** (each 2.1.2 release candidate, on Docker Desktop, with an instance of 2.1.1 holding a saved
report; install the 2.1.1 final with its one-liner if none is left):

- [ ] `fieldset upgrade --to <ver>` from 2.1.1: exit 0; `fieldset status` and `fieldset doctor` show no failure.
- [ ] Open a saved report in the admin dashboard: it shows results and when they were calculated; refresh recalculates
      them.

## Docs site

The site `https://beyerenrico.github.io/fieldset/` (ADR 0094) holds one copy per minor version from 2.1 on, the home
page, `latest/` and Fieldset Spec 1.0 at its `$id`. Nobody has to publish it by hand: when a `Release` run succeeds,
the `Docs` workflow (`.github/workflows/docs.yml`) publishes that release's minor, about 2.5 hours after the tag
(plan estimate). A failed release publishes nothing. Its jobs:

| Job | What it does |
|---|---|
| resolve | proves the release before anything builds: a successful tag push of this repository's `release.yml`, the tag's commit is the run's head commit, the GitHub release is published, and `cosign verify-blob` accepts its `release-manifest.json` with the identity `install.sh` checks |
| build | untrusted (`contents: read`): builds the copy, and when due the root, from a worktree of the tag with `main`'s `docs/site/` and the OpenAPI export of the released app image. A tag the version rules skip (alpha, beta, below 2.1, older than the stored copy, an rc once the minor has a final) ends the run green with a notice. It also warns when a published release is newer than its stored copy |
| store | runs only `docs/site/store/` from `main` (git, jq, grep, cmp): verifies the artifact (paths, `versions.json`, spec files, install URLs, the reference page's CSP) and appends one commit to the `docs-site` branch without force (environment `docs-store`) |
| deploy | deploys the stored `docs-site` commit to Pages (environment `github-pages`); every deploy replaces the whole site |

`docs-site` holds the whole deployable site, one commit per publish. Old builds cannot be rebuilt, so it is their
only copy: never force-push, rebase or delete it.

**Re-run a publish** (for example after a failed run, or when the warning names a release whose run was dropped) by
dispatching the workflow from `main`; `store` and `deploy` run only there:

```sh
gh workflow run docs.yml --ref main -f tag=vX.Y.Z      # mode publish (the default): build, store and deploy the tag
gh workflow run docs.yml --ref main -f mode=redeploy   # deploy docs-site as it is, nothing is built
```

**Verify** a publish, or what is live:

```sh
gh run list --workflow docs.yml --limit 1                    # the newest Docs run and its id
gh run watch <run id> --exit-status                          # follow it; non-zero when a job failed
git ls-remote origin refs/heads/docs-site                    # the stored commit the deploy must match
curl -fsSL https://beyerenrico.github.io/fieldset/versions.json | jq .   # the live copies and latest
```

Each copy is served at `https://beyerenrico.github.io/fieldset/<minor>/` (for example `/fieldset/2.1/`),
`/fieldset/latest/` leads to the newest, and the spec is at its `$id`,
`https://beyerenrico.github.io/fieldset/spec/form-definition/1.0/meta-schema.json`. A deploy whose stored commit is no
longer the head of `docs-site` (a re-run after a later publish or a revert) fails on purpose; `mode=redeploy` deploys
the head.

**Root refresh.** The home page, `latest/` and the spec files change only when the publish is the newest version: the
newest final, or the newest rc while no final exists. `versions.json` changes on every publish. A re-run of the newest
tag refreshes the root, which is how home-page fixes go live: merge the fix to `main`, then dispatch the newest tag. The
root's theme and config always come from `main`; its pages and screenshots come from the tag's `docs/site/root/`, and
from `main` only for tags that predate the site (2.1). A page fix for a newer minor therefore needs a new tag.

**Remove a bad copy** with a revert commit on `docs-site`, never a force-push, then redeploy:

```sh
git fetch origin docs-site
git worktree add ../fieldset-docs-site origin/docs-site
git -C ../fieldset-docs-site revert --no-edit <bad publish commit>
git -C ../fieldset-docs-site push origin HEAD:docs-site
gh workflow run docs.yml --ref main -f mode=redeploy
```

The revert also restores the previous `versions.json` and, if that publish refreshed the root, the previous root.

**One-time setup (human steps, plan U10).** Settings the workflow relies on; none of them is set by the autonomous
build:

1. **Pages source.** Settings → Pages → Build and deployment → Source: GitHub Actions.
2. **`github-pages` environment.** Deployment branches: `main` only.
3. **`docs-store` environment.** Create it; deployment branches: `main` only. It holds the job that writes
   `docs-site`.
4. **`docs-site` ruleset.** A branch ruleset targeting `docs-site` with "Restrict deletions" and "Block force pushes".

**Known gap (deferred):** `finalize` marks every final release as GitHub's latest (`gh release edit --latest`), so a
late 2.1.x after 2.2.0 would move `releases/latest` backwards. The home page and the rc notice use versioned install
commands, but every copy's guides use `releases/latest/download/install.sh`. Fix `finalize` before a patch of an
older minor ships after a newer minor's final.

## Building and testing a release locally (harness)

The harness (KTD23, ADR 0064, ADR 0067) runs without any account. It uses a `registry:3`, a static release
server for `dist/`, a buildx builder on `network=host` and a cosign key pair generated per run.

```sh
vendor/bin/pest --testsuite=Install --filter=HarnessRelease           # build, sign, serve and check a release
FIELDSET_TEST_PLATFORM=linux/amd64 vendor/bin/pest --testsuite=Install --filter=HarnessRelease   # emulated amd64
FIELDSET_HARNESS_PLATFORMS=linux/amd64,linux/arm64 vendor/bin/pest --testsuite=Install --filter=HarnessRelease
```

`tests/Support/Docker/ReleaseHarness.php` drives it. `ReleaseHarness::start()` brings up the compose project
`fieldset-test-harness-<id>` from `tests/Install/Harness/compose.harness.yaml`. `publish($version)` then runs the
steps of the workflow:

1. build and push the app image with SBOM and provenance;
2. sign it with the local key;
3. mirror every third-party pin by digest;
4. run `release:manifest --stack-file`, which also writes `compose.stack.yaml`, and `cosign sign-blob --bundle`;
5. lay out `dist/download/v<version>/`, plus `dist/latest/download/` for stable versions only.

Proxy-mode journeys (J3) call `startProxy()`, which creates the external network `fieldset-test-proxy-<id>` and starts
Traefik from the `proxy` profile on it. `stop()` removes the containers, the networks (Traefik's too), the volume,
the builder and the run directory.

- Registry: `ReleaseHarness::registry()` (`127.0.0.1:<port>`) works for the host, the Docker daemon and
  host-network containers. Installs pull `<registry>/<repository>@<digest>` there (`FIELDSET_REGISTRY`).
- Release server: `ReleaseHarness::releaseUrl()` stands in for
  `https://github.com/beyerenrico/fieldset/releases` (`FIELDSET_RELEASE_URL`).
- Local public key: `$harness->cosign->publicKey()`. Only harness builds accept it (`FIELDSET_TRUST_KEY`, U4).

Leftovers from an interrupted run (from the repository root). The compose file requires `FIELDSET_HARNESS_DIST`,
which `ReleaseHarness` sets to the run's `dist/`; give it as an absolute path, because Compose reads a relative
one as a volume name or resolves it against `tests/Install/Harness/`. The profiles match `ReleaseHarness::stop()`.
Traefik's network is external, so `down` leaves it; remove it after a proxy journey:

```sh
FIELDSET_HARNESS_DIST="$PWD/storage/fieldset-test-harness/<id>/dist" docker compose -p fieldset-test-harness-<id> \
  -f tests/Install/Harness/compose.harness.yaml --profile forwarder --profile acme --profile proxy \
  down --volumes --remove-orphans
docker network rm fieldset-test-proxy-<id>   # only after a proxy journey
docker buildx rm fieldset-test-harness-<id>
rm -rf storage/fieldset-test-harness/<id>
```

Mirrored blobs and the build cache stay in `storage/fieldset-test-cache/`; delete that directory to reclaim the disk
space.

Lint locally like CI:

```sh
docker run --rm -v "$PWD:/repo:ro" -w /repo rhysd/actionlint:1.7.12 -color
docker run --rm -i hadolint/hadolint:v2.15.1 hadolint - < docker/app/Dockerfile
docker run --rm -v "$PWD:/mnt:ro" -w /mnt koalaman/shellcheck:v0.11.0 docker/app/entrypoint.sh docker/app/fieldset distribution/*.sh scripts/wsl/facts.sh
```

## Post-publish checklist

Run these checks after the release is out of draft and before announcing it. The release workflow
already pulls every digest without credentials (`verify-public`) and compares the anonymously downloaded manifest
(`finalize`); the checks below repeat that from outside GitHub's runners and cover what CI cannot. None of them can
run inside the autonomous build (§15.1). All of them are **not yet verified**. The list ends with the six checks R52
names.

- [ ] A real provider's SMTP server with `fieldset config set mail smtp`, once with STARTTLS (port 587) and once with
      implicit TLS (`--smtp-encryption tls`, port 465): the test mail arrives and so does an invitation. J4 covers
      only Mailpit over plain SMTP with AUTH (ADR 0080). Not yet verified.
- [ ] The German capture banner read by a real screen reader (VoiceOver or NVDA): the region's name, both
      variants' text and the operator command (ADR 0080). Not yet verified.
- [ ] **Stable releases only:** the `/platform` instance page's update check (when turned on) finds the published
      release and shows its notes link. The check reads `latest/download/`, which never points to a pre-release, so a
      pre-release cannot pass this check. Not yet verified.
- [ ] **GHCR package public.** The package `fieldset` is public (irreversible), made public only after the
      first pre-release passed review. Not yet verified.
- [ ] **Anonymous pull of every digest.** Logged out of GHCR (`docker logout ghcr.io`), every image digest in the
      release's `release-manifest.json` pulls on amd64 and on arm64:

  ```sh
  curl -fsSLO https://github.com/beyerenrico/fieldset/releases/download/v<ver>/release-manifest.json
  jq -r '.images[] | "\(.registry)/\(.repository)@\(.digest)"' release-manifest.json | while read -r ref; do
    docker pull --platform linux/amd64 "$ref" && docker image rm "$ref" && docker pull --platform linux/arm64 "$ref"
  done
  ```

  Remove the image between the two pulls: Docker's classic image store refuses a second platform of the same digest
  ("cannot overwrite digest").

  Not yet verified.
- [ ] **`cosign verify` with the pinned identity**, run from a machine outside CI:

  ```sh
  cosign verify ghcr.io/beyerenrico/fieldset@<digest> \
    --certificate-identity-regexp '<CERTIFICATE_IDENTITY_REGEXP from release.yml>' \
    --certificate-oidc-issuer https://token.actions.githubusercontent.com \
    --certificate-github-workflow-trigger push
  cosign verify-blob release-manifest.json --bundle release-manifest.json.sigstore.json \
    --certificate-identity-regexp '<same>' --certificate-oidc-issuer https://token.actions.githubusercontent.com \
    --certificate-github-workflow-trigger push
  ```

  These are the flags `install.sh` (`fieldset_install_trust()`) and `fieldset` (`SignerIdentity`) verify the manifest with
  (ADR 0070). Not yet verified.
- [ ] **One-liner install on a throwaway VPS against Let's Encrypt staging:** domain mode with `--acme-staging`
      (a pre-release needs the `download/v<ver>` form with `--version`, see [Installing a release](#installing-a-release)),
      then the owner's sign-in link, `fieldset status`, `fieldset backup`, and `fieldset config set acme-ca production` once the
      staging certificate was issued. Follow the shipped `agent-guide.md`'s "VPS install" recipe. Not yet verified.
- [ ] **A real tunnel.** A cloudflared quick tunnel (`fieldset tunnel start`) reached from a phone: the hostname read from
      `/quicktunnel`, what cloudflared forwards to the app (Host, `X-Forwarded-*`), and throttling, since every
      tunnel client shares cloudflared's address for throttles and audit IPs (ADR 0082). Not yet verified.
- [ ] **The local CA on one iOS and one Android device** (`fieldset config set phone-https local-ca`): the trust page,
      the CA installed and trusted, then the Field App installs to the home screen, works offline and syncs.
      Not yet verified.

