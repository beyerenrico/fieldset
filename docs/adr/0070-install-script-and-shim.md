# 0070: install.sh, the fsa shim, host facts and manifest verification (as built)
- Status: accepted; amended 2026-10-18 at the M9 gate (sign-in link hand-over policy, exit 2 for missing input;
  private HOME, reserved paths, command position, terminal and `--json` rules, launcher error codes, `--yes`,
  link fallback, the image's `fsa` wrapper, the `DOCKER_DEFAULT_PLATFORM` pass-through); amended 2026-10-18 at the
  M10 gate by ADR 0079 (a value flag before the command keeps its value)
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
  (Amended 2026-10-18: error codes and `retryable` match fsa's (ADR 0066). `docker_unavailable` (exit 10),
  `cosign_unavailable` and `image_unavailable` (exit 3) are retryable; a manifest for another version is exit 3
  `release_mismatch`. HTTP 404 or 410 for the manifest is exit 2 `release_not_found`, for the signature bundle exit
  3 `manifest_missing`; any other download failure is exit 3 `release_unavailable` (retryable).)
- Flags: `--dir`, `--version` and `--json` are read and passed on (`--dir` made absolute, default
  `/opt/form-schema-app` for root on Linux, else `~/form-schema-app`); `--link`, `--no-link` and `--help` are
  handled and not passed. Everything else goes to `fsa install` unchanged. With `--json`, install.sh's own
  failures print the result envelope on stdout and its progress is NDJSON on stderr (KTD3).
  (Amended 2026-10-18: `--yes` is read too and passed on; it implies no PATH link unless `--link` is given. With
  `--json` stderr is NDJSON only: Docker's stderr goes to a file whose last line ends up in the error message, and
  the shell's own messages of failed steps (`mktemp`, `mkdir`, `cp`, `ln`, nonce writes) are discarded.)
- Instance directory: refused when it is `/`, a symlink, contains `:` `,` or a line break, or exists with
  content other than `backups/` and no marker. Created 0700 with the `.fsa-instance` marker; the verified
  manifest and bundle go to `releases/<version>/` (an install of another version never overwrites the release
  an instance runs); the shim is copied from the pulled image (`/var/www/html/distribution/shim.sh`) to
  `<dir>/fsa`, mode 0700, so it needs no checksum tooling on the host.
  (Amended 2026-10-18: a reserved instance directory (see the ops container's file-path flags) is exit 2
  `path_reserved`, checked on the normalized path.)
- `--link` (or "y" at the prompt, only with a terminal) links `~/.local/bin/fsa` (`/usr/local/bin` for root) to
  the shim, never over an existing file (ADR 0049).
  (Amended 2026-10-18: the prompt appears only with a terminal (`FSA_TTY`), without `--json` and without `--yes`;
  a link that cannot be created is reported as a warning instead of passing silently.)

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
| `DOCKER_DEFAULT_PLATFORM` | Docker's own variable, passed through when set (amended 2026-10-18, below) |
| `FSA_TEST_DNS` | shim only: passed through when set. Fixed DNS answers for the doctor (`host=ip[,ip][;…]`), honoured only by release-harness images (`BuildFlavor`); production images ignore it (amended 2026-10-18, ADR 0075) |

(Amended 2026-10-18: `DOCKER_DEFAULT_PLATFORM`. When the caller sets it, install.sh (`fsa_install_platform_ok`,
with the other no-network input checks) and the shim (`fsa_platform_ok`, before `docker info`) accept only
`linux/amd64` or `linux/arm64`, optionally with a variant (`linux/arm64/v8`); anything else is exit 2
`invalid_platform`. The launchers keep it in their environment, so the docker CLI applies it to their own pulls,
creates and runs (the app image, the shim copy, the ops container), and pass it into the ops container with
`-e DOCKER_DEFAULT_PLATFORM`. There `HostFacts::platform` validates it the same way (a parity test runs the same
values through all three), and every docker and compose process fsa starts inherits it through Symfony Process's
inherited environment (tested with a PHP CLI child), so compose pulls and runs the services as that platform too.
Cosign is the exception: install.sh pulls and creates it with the variable cleared (`fsa_install_cosign_docker`),
and `ManifestVerifier` pulls and runs it with `enginePlatform` (`ContainerSpec`, `DockerGateway::pullImage`), so
verification runs as the engine's own platform. Verifying does not depend on the platform, and Docker's classic
image store keeps one platform per by-digest reference: with a native cosign present, an amd64 run failed to
re-pull it ("cannot overwrite digest"). The doctor's `architecture` check compares the selected platform (variant
dropped) with the release's platforms and warns when it differs from the engine's, because emulation is much
slower; its details carry `platform`, `engine_platform`, `emulated` and `docker_default_platform`. When
`emulated`, `fsa install` renders every memory limit doubled (`RenderContext::EMULATION_MEMORY_FACTOR`): each
emulated process carries its translator, and amd64 mailpit on Apple Silicon took about 57 MiB instead of 13, so
its E16 limit (48 MiB, ADR 0062) OOM-killed it. Uses: amd64 images on Apple Silicon, and J1/J12 under emulation
(BUILD_PROMPT_V2 §15.6): the harness sets it when a release has one platform that is not the engine's. It is not
stored in the state, so every later `fsa` call needs it in the environment again, as Docker itself does; a
detached supervisor (upgrade, restore) must pass it on in its container env.)

**Ops container** (`fsa_run` in the shim, used by both launchers): `docker run --rm --init --label
app.form-schema.role=ops --user <user> [--group-add <socket gid>] -e HOME=/tmp -e DOCKER_CONFIG=/tmp/.docker
-e DOCKER_HOST=unix:///var/run/docker.sock -v <socket>:/var/run/docker.sock -v <dir>:<dir> -w <dir>
[-v <parent>:<parent>[:ro] …] -e FSA_… --entrypoint fsa <image> <command> <args…> --dir <dir>`.
(Amended 2026-10-18: HOME and DOCKER_CONFIG live on a container-private tmpfs instead of `/tmp`:
`--tmpfs /run/fsa-home:mode=0700,uid=<user uid>,gid=<user gid> -e HOME=/run/fsa-home
-e DOCKER_CONFIG=/run/fsa-home/.docker`. A file flag pointing into `/tmp` used to mount the host's shared `/tmp`
over the socket-holding container's Docker config, where another local user could plant a CLI plugin. The first
argument that is no flag (or the value of a value-taking fsa option: `--dir`, a file-path flag or one listed in
`fsa_value_flags`, kept in step by ShimScriptTest; amended by ADR 0079) is the command; it moves to the front with
`--dir <dir>` right after it (`<command> --dir <dir> <args…>`), so leading flags work and a literal `--` keeps its
positionals.)
- User (KTD2): rootful → `<host uid>:<host gid>` plus the socket's group (`stat -L -c %g`); rootless and
  desktop → `0:0`. Socket: rootless → `DOCKER_HOST` (unix) or `$XDG_RUNTIME_DIR/docker.sock`; rootful →
  `DOCKER_HOST` (unix) or `/var/run/docker.sock`; desktop → `/var/run/docker.sock` (the VM side). A non-unix
  `DOCKER_HOST` exits 10.
- Terminal: `-it` with stdin from `/dev/tty` only when `/dev/tty` opens, stdout is a terminal and `--json` is
  absent (`-t` merges stderr into stdout). Otherwise the shim passes stdin with `-i` (for `--…-stdin` flags) and
  install.sh passes none. A `docker run` failure (125–127) exits 10.
  (Amended 2026-10-18: `-it` only when stdout is a terminal, `/dev/tty` opens and `--json` is absent, and for the
  shim also only when stdin is a terminal, so piped stdin keeps `-i`. install.sh keeps `/dev/tty` as the
  container's stdin, because its own stdin is the `curl` pipe. With `--json` stderr is NDJSON only: launcher
  messages and Docker's own lines become events, the command's events pass through, and shell errors of failed
  launcher steps are discarded. A 125–127 failure whose stderr says "Mounts denied" or "not shared" is exit 3
  `path_not_shared` with the file-sharing hint; any other stays exit 10 `docker_run_failed`. An unreachable daemon
  is exit 10 `docker_unavailable`, retryable, as in fsa.)
- File-path flags (`FilePathFlags`, `fsa_file_flag_mode`): outputs `--secrets-file`, `--output`, `--…-out`;
  inputs any other `--…-file` and `--from-backup`. The value is made absolute against the caller's directory,
  passed as `--flag=<abs>`, and its parent mounted at the same path (read-only for inputs) unless it lies in the
  instance directory. Duplicates collapse, read-write wins, and a path with `:` `,` or a line break, a missing
  parent directory, or `/` as the parent exits 2. Positional path arguments are not mounted: commands that read
  host files take them as flags. `--dir` must name this instance (exit 2 otherwise). `FSA_TRUST_KEY`'s
  directory is mounted read-only.
  (Amended 2026-10-18: reserved paths. A mount directory is exit 2 `path_reserved` when it equals, contains or
  lies inside `/tmp /var /usr /etc /run /proc /sys /dev /bin /sbin /lib /lib64 /root`, or is exactly `/` or
  `/home`: it would cover files of the ops image or hand the container a directory other users can write to.
  File-flag parents are checked on the path as written (lexically normalized) and again on the physical directory
  (`cd -P`) that gets mounted; the shim and install.sh check the instance directory as written.
  `/opt/form-schema-app` and paths under home stay allowed. One list in three places: `fsa_reserved_path` (shim),
  `fsa_install_reserved` (install.sh) and `LaunchGuard::RESERVED` plus `RESERVED_EXACT`; a parity test in
  `tests/Unit/Distribution/InstallScriptTest.php` runs the same paths through all three and compares the lists.)
- Nonce: written into the instance directory and every read-write mount before the run, removed afterwards (and
  by the EXIT/INT/TERM traps). Every `fsa:*` command started with `FSA_NONCE` checks it first
  (`LaunchGuard::assertVisible`, from `FsaCommand`): a missing file is exit 3 `path_not_shared` with a
  file-sharing hint, before anything could be written inside the VM instead of the host. Then
  `LaunchGuard::assertFilePaths`: a file-path option outside the instance directory and the mounts (read-write
  ones for outputs) is exit 2 `path_not_mounted`. Commands without `FSA_NONCE` (the detached supervisor) skip both.
  (Amended 2026-10-18: `assertVisible` first refuses any reserved `FSA_MOUNTS` entry, exit 2 `path_reserved`,
  before any Docker call, so an older launcher is stopped too. The instance directory is left to the launchers.)

**Shim** (`distribution/shim.sh` → `<dir>/fsa`). It resolves its own directory through symlinks, reads the ops
image from `state.json` `digests.app` (InstanceState's 4-space pretty print; validated as
`…@sha256:<64 hex>`), never from a manifest, so a failed shim swap cannot lock the owner out (KTD19). Without a
usable state it exits 3 and points at install.sh. install.sh sources the shim from the verified image with
`FSA_SHIM_LIBRARY=1` and calls `fsa_run <dir> <image> install …`; this function and its exit-code behaviour are
the stable install.sh–shim contract.
(Amended 2026-10-18: the image's `fsa` wrapper (`docker/app/fsa`) finds the command the same way: the first
argument that is no flag (or the value of a value-taking fsa option: `value_flags`, kept in step by
ImageContractTest; amended by ADR 0079) is the command, and leading flags move after it, so `fsa --json status` runs
`fsa:status --json` and `fsa --behind-proxy traefik install` runs `fsa:install --behind-proxy traefik`. An unknown
command exits 2 `unknown_command` (the envelope on stdout with `--json`, else stderr) instead of reaching
`artisan fsa:<x>`, with the list of commands in the hint, which since ADR 0079 also says to put flags after the
command or write them as `--flag=value`;
`tests/Unit/Docker/ImageContractTest.php` keeps the list in step with the registered `fsa:*` commands.)

**Browser hand-over.** A command calls `FsaContext::openInBrowser($url)`: with a desktop session it writes the
URL (http/https, RFC 3986 characters without quotes or braces) to `FSA_OPEN_URL_FILE` (0600) and returns true.
After the container exits, the shim reads and deletes the file, re-validates the URL and runs `xdg-open` or
`open` with it as a single argument, never through a shell. This replaces parsing `open_url` from stdout: the
link never reaches the transcript of an agent driving fsa (ADR 0056), and stdout stays streamed. The command's
JSON says that it handed a URL over; without a desktop session it reports the link through `secrets` or
`--secrets-file`.
(Amended 2026-10-18: a desktop session alone no longer opens the browser, since an agent can run on a desktop
too. A sign-in link (`Lifecycle\SignInDelivery`) goes into `--secrets-file` when one is given; to the browser
only with `--open`, or for a person at a desktop terminal (TTY, desktop session, no `--json`;
`FsaContext::handOverAllowed()`); otherwise into `secrets`. `data.sign_in_link.delivered` says which way
(`browser`, `secrets_file`, `secrets`), and after a hand-over its `hint` names
`fsa login-link <email> --secrets-file <file>` in case no browser opened.)
(Amended 2026-10-18, shim side: the opener runs first. If it fails, the link goes to `/dev/tty` when a terminal
opens, never to stdout or stderr. Otherwise the 0600 file stays, and a warning names it with the hint
`fsa login-link <email> --secrets-file <file>`. The file is deleted only after the link was opened or shown.)

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
  the journey's probe with the real command and asserts exit 2 (`input_required`) for a missing required flag
  without a TTY. (Amended 2026-10-18: was exit 6, which stays reserved for confirmations, see ADR 0072.)
- U18 swaps `<dir>/fsa` from the new image only after commit; the shim keeps reading the state.
- The bootstrap journey (`tests/Install/Journeys/BootstrapJourneyTest.php`) needs the harness build (minutes on
  the first run) and runs the ops container through a probe until U5's commands exist.
