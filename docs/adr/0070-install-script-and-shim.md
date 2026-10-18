# 0070: install.sh, the fsa shim, host facts and manifest verification (as built)
- Status: accepted
- Date: 2026-10-18

## Context
Plan unit U4 (PLAN M9-T07): a POSIX bootstrap that verifies the release before trusting any digest (R7, R8, R58),
pulls the app image by digest and hands over to `fsa install`, and a shim for every later command (E3, ADR 0049).
The ops container cannot observe the host, so the launchers pass host facts as environment variables and mount
what the command needs at the same path (BUILD_PROMPT_V2 §6.2, KTD2). Shell is allowed only here (§13.1).
U5 (`fsa install`, doctor, status …) and U18 (`fsa upgrade`) build on the contract fixed here.

## Decision

**install.sh** (`distribution/install.sh`, POSIX sh, dash-clean, shellcheck-clean, ~220 lines).
- Only function definitions, and one guarded call on the last line: `[ "${FSA_INSTALL_LIBRARY:-}" = 1 ] ||
  { fsa_install_main "$@"; }`. A truncated download is a syntax error or defines functions only; a brace group
  is parsed whole, so even a cut inside the last line runs nothing (tested at every line boundary, dash and sh).
  `FSA_INSTALL_LIBRARY=1` lets tests source it and call single functions. The plan's "one function" became
  several small ones for testability; the property it protects is unchanged.
- Order: flags and env checks (exit 2, no network) → Docker CLI and daemon (exit 10) → download
  `release-manifest.json` and its `.sigstore.json` from `<FSA_RELEASE_URL>/download/v<version>/` or
  `/latest/download/` with `curl --proto '=https' --tlsv1.2 -fsSL` → verify (exit 3) → read the app image from
  the verified manifest → `docker pull <FSA_REGISTRY or registry>/<repository>@<digest>` (exit 3) → instance
  directory → shim → hand-over. Nothing but the cosign image is pulled before verification.
- Flags: `--dir`, `--version` and `--json` are read and passed on (`--dir` made absolute, default
  `/opt/form-schema-app` for root on Linux, else `~/form-schema-app`); `--link`, `--no-link` and `--help` are
  handled and not passed. Everything else goes to `fsa install` unchanged. With `--json`, install.sh's own
  failures print the result envelope on stdout and its progress is NDJSON on stderr (KTD3).
- Instance directory: refused when it is `/`, a symlink, contains `:` `,` or a line break, or exists with
  content other than `backups/` and no marker. Created 0700 with the `.fsa-instance` marker; the verified
  manifest and bundle go to `releases/<version>/` (an install of another version never overwrites the release
  an instance runs); the shim is copied from the pulled image (`/var/www/html/distribution/shim.sh`) to
  `<dir>/fsa`, mode 0700, so it needs no checksum tooling on the host.
- `--link` (or "y" at the prompt, only with a terminal) links `~/.local/bin/fsa` (`/usr/local/bin` for root) to
  the shim, never over an existing file (ADR 0049).

**Verification (R58).** One source of truth in PHP: `SignerIdentity::production()` (anchored regexp for
`https://github.com/beyerenrico/form-schema-app/.github/workflows/release.yml@refs/tags/v<semver>`, issuer
`https://token.actions.githubusercontent.com`, trigger `push`) and `ManifestVerifier::COSIGN_IMAGE`
(`ghcr.io/sigstore/cosign/cosign:v3.1.3@sha256:9e5c2f2e…`). install.sh carries the same literals in
`fsa_install_trust()`, and release.yml the same regexp; unit tests fail when any of the three differ.
- install.sh runs `cosign verify-blob --bundle … --certificate-identity-regexp … --certificate-oidc-issuer …
  --certificate-github-workflow-trigger push` in that image via `docker create` + `docker cp` + `docker start -a`
  rather than a bind mount, so a temporary directory Docker Desktop or Colima does not share cannot turn into an
  empty mount. Keyless verification fetches Sigstore's trusted root through TUF (network); there is no skip.
- `ManifestVerifier` (`App\Domain\Instance\Release`) runs the same image and arguments through
  `DockerGateway::runContainer`, with the manifest bytes on stdin (blob `-`) and the bundle mounted read-only
  from the instance directory, re-reads the file to refuse a change during verification, then validates the
  schema and returns the `ReleaseManifest`. Every failure is exit 3 (`manifest_signature_invalid`,
  `manifest_invalid`, `cosign_unavailable`, `manifest_missing`); a timeout is retryable. `verifySignature()`
  checks a blob without the schema. A pinned trusted root can only be injected through the constructor (tests).
  This amends ADR 0060: fsa uses the pinned cosign image like install.sh, not the cosign binary in the image
  (QUESTIONS 28).
- The production path is tested offline with real cosign against a real keyless bundle checked in under
  `tests/Fixtures/Release/keyless/` (goreleaser v2.18.3 `checksums.txt`, identity
  `https://github.com/goreleaser/goreleaser/.github/workflows/release.yml@refs/tags/v2.18.3`, trusted root from
  the Sigstore TUF repository, `source.json`): the same-shape identity for that repository verifies; ours, another
  workflow path and a tampered blob are refused.

**Harness build switch.** Production is the default everywhere; only the release harness flips it.
- install.sh: the single line `build=production` in `fsa_install_trust()`. `InstallScriptBuild::harness()`
  (tests/Support) writes a copy with `build=harness` when the harness publishes; release.yml fails when the
  checked-in file is not the production build and copies it unchanged.
- Image: build argument `FSA_BUILD` (default `production`; the harness passes `harness`) written to
  `distribution/.build-flavor` at build time and read by `BuildFlavor::current()`. A file, not `ENV`, so
  `docker run -e` cannot change it; anything but exactly `harness` is production.
- A harness build accepts `FSA_TRUST_KEY` (cosign public key; `--key … --insecure-ignore-tlog=true`,
  `--network none`) and plain-http `FSA_RELEASE_URL`. A production `install.sh` exits 2 for either
  (`trust_key_not_allowed`, `insecure_release_url`). Inside the image, `TrustPolicy::fromEnvironment()` refuses
  only `FSA_TRUST_KEY` (exit 2): `fsa` reads no release URL yet, because `install.sh` hands it the verified
  manifest (`FSA_RELEASE_MANIFEST`). U18 (upgrade) and U20 (update check), which fetch manifests from inside the
  image, must enforce https there as well. (Corrected 2026-10-18 at the M9 gate.)

**Host facts** (passed by name with `-e`; `HostFacts::fromEnvironment()` drops invalid values):

| Variable | Value |
|---|---|
| `FSA_LAUNCHER` | `install.sh` or `shim` |
| `FSA_TTY` | `1` only when the container got `-it` |
| `FSA_HOST_UID`, `FSA_HOST_GID` | `id -u`, `id -g` of the caller |
| `FSA_HOST_OS` | `uname -s` in lower case |
| `FSA_INSTANCE_DIR` | absolute instance directory |
| `FSA_LAN_ADDRESSES` | space-separated IPv4 candidates (`ip` or `ifconfig`; no loopback, Docker/VM bridges, tunnels) |
| `FSA_DESKTOP` | `1` when a browser can be opened (Linux `DISPLAY`/`WAYLAND_DISPLAY` + `xdg-open`; macOS `open` outside SSH) |
| `FSA_TZ` | `TZ`, else `/etc/timezone`, else the `/etc/localtime` link target (IANA name or empty) |
| `FSA_DOCKER_RUNTIME` | `rootless` (`docker info` security option), `desktop` (non-Linux host, Docker Desktop, OrbStack, Colima, Lima, Rancher Desktop) or `rootful` |
| `FSA_CONTAINER_UID` | in-container owner UID: host UID on rootful, `0` otherwise (`DockerRuntime`) |
| `FSA_DOCKER_SOCKET` | the socket path mounted at `/var/run/docker.sock` |
| `FSA_NONCE` | `.fsa-nonce-<32 hex>` |
| `FSA_MOUNTS` | one `ro:<dir>` or `rw:<dir>` line per file-flag parent directory |
| `FSA_OPEN_URL_FILE` | `.fsa-open-url-<32 hex>` in the instance directory, only with a desktop session |
| `FSA_TRUST_KEY`, `FSA_REGISTRY`, `FSA_RELEASE_URL` | passed through when set |
| `FSA_RELEASE_MANIFEST` | install.sh only: `<dir>/releases/<version>/release-manifest.json` (verified) |

**Ops container** (`fsa_run` in the shim, used by both launchers): `docker run --rm --init --label
app.form-schema.role=ops --user <user> [--group-add <socket gid>] -e HOME=/tmp -e DOCKER_CONFIG=/tmp/.docker
-e DOCKER_HOST=unix:///var/run/docker.sock -v <socket>:/var/run/docker.sock -v <dir>:<dir> -w <dir>
[-v <parent>:<parent>[:ro] …] -e FSA_… --entrypoint fsa <image> <command> <args…> --dir <dir>`.
- User (KTD2): rootful → `<host uid>:<host gid>` plus the socket's group (`stat -L -c %g`); rootless and
  desktop → `0:0`. Socket: rootless → `DOCKER_HOST` (unix) or `$XDG_RUNTIME_DIR/docker.sock`; rootful →
  `DOCKER_HOST` (unix) or `/var/run/docker.sock`; desktop → `/var/run/docker.sock` (the VM side). A non-unix
  `DOCKER_HOST` exits 10.
- Terminal: `-it` with stdin from `/dev/tty` only when `/dev/tty` opens, stdout is a terminal and `--json` is
  absent (`-t` merges stderr into stdout). Otherwise the shim passes stdin with `-i` (for `--…-stdin` flags) and
  install.sh passes none. A `docker run` failure (125–127) exits 10.
- File-path flags (`FilePathFlags`, `fsa_file_flag_mode`): outputs `--secrets-file`, `--output`, `--…-out`;
  inputs any other `--…-file` and `--from-backup`. The value is made absolute against the caller's directory,
  passed as `--flag=<abs>`, and its parent mounted at the same path (read-only for inputs) unless it lies in the
  instance directory. Duplicates collapse, read-write wins, and a path with `:` `,` or a line break, a missing
  parent directory, or `/` as the parent exits 2. Positional path arguments are not mounted: commands that read
  host files take them as flags. `--dir` must name this instance (exit 2 otherwise). `FSA_TRUST_KEY`'s
  directory is mounted read-only.
- Nonce: written into the instance directory and every read-write mount before the run, removed afterwards (and
  by the EXIT/INT/TERM traps). Every `fsa:*` command started with `FSA_NONCE` checks it first
  (`LaunchGuard::assertVisible`, from `FsaCommand`): a missing file is exit 3 `path_not_shared` with a
  file-sharing hint, before anything could be written inside the VM instead of the host. Then
  `LaunchGuard::assertFilePaths`: a file-path option outside the instance directory and the mounts (read-write
  ones for outputs) is exit 2 `path_not_mounted`. Commands without `FSA_NONCE` (the detached supervisor) skip both.

**Shim** (`distribution/shim.sh` → `<dir>/fsa`). It resolves its own directory through symlinks, reads the ops
image from `state.json` `digests.app` (InstanceState's 4-space pretty print; validated as
`…@sha256:<64 hex>`), never from a manifest, so a failed shim swap cannot lock the owner out (KTD19). Without a
usable state it exits 3 and points at install.sh. install.sh sources the shim from the verified image with
`FSA_SHIM_LIBRARY=1` and calls `fsa_run <dir> <image> install …`; this function and its exit-code behaviour are
the stable install.sh–shim contract.

**Browser hand-over.** A command calls `FsaContext::openInBrowser($url)`: with a desktop session it writes the
URL (http/https, RFC 3986 characters without quotes or braces) to `FSA_OPEN_URL_FILE` (0600) and returns true.
After the container exits, the shim reads and deletes the file, re-validates the URL and runs `xdg-open` or
`open` with it as a single argument, never through a shell. This replaces parsing `open_url` from stdout: the
link never reaches the transcript of an agent driving fsa (ADR 0056), and stdout stays streamed. The command's
JSON says that it handed a URL over; without a desktop session it reports the link through `secrets` or
`--secrets-file`.

## Alternatives considered
- **Bind-mounting install.sh's temporary directory into cosign:** silently empty on Colima and unshared paths.
- **A single shell function:** untestable in parts; the guarded last line keeps the truncation property.
- **`ENV FSA_HARNESS=1` or a runtime flag for the harness:** a production image would accept a test key via
  `docker run -e`, which R58 forbids.
- **`open_url` read from the command's stdout by the shim:** the sign-in link would sit in the agent's transcript,
  and the shim would have to buffer output that `-t` mixes with progress.
- **The in-image cosign binary for fsa:** two cosign builds to keep in step; the gateway path is testable end to
  end with real cosign and the recording fake.
- **A shim that takes the image from an environment variable:** would let any caller swap the image that gets
  the socket; install.sh sources the shim instead.

## Consequences
- U5: build `fsa install` on `FSA_RELEASE_MANIFEST` + `ManifestVerifier` (bind `TrustPolicy`/`ManifestSchema` are
  registered), store `digests.app` as the exact reference install.sh pulled (mirror applied), create the state
  with `HostFacts::ownerUidInContainers()`, use `HostFacts` for LAN, TZ and TTY, `openInBrowser()` for the
  owner link, and treat stale `.fsa-nonce-*` / `.fsa-open-url-*` files (interrupted runs) in doctor. J1 replaces
  the journey's probe with the real command and asserts exit 6 for a missing required flag without a TTY.
- U18 swaps `<dir>/fsa` from the new image only after commit; the shim keeps reading the state.
- The bootstrap journey (`tests/Install/Journeys/BootstrapJourneyTest.php`) needs the harness build (minutes on
  the first run) and runs the ops container through a probe until U5's commands exist.
