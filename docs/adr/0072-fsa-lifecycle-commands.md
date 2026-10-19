# 0072: fsa lifecycle commands for local mode: install phases, resume, ports, doctor, smoke
- Status: accepted; amended 2026-10-18 at the M9 gate (exit codes, ports check, lock heartbeat, sign-in link
  delivery); the uninstall paragraph is superseded by ADR 0073; amended 2026-10-18 at the M10 gate by ADR 0078
  (repeated install flags, `--trusted-proxy` match, ports on a shared proxy network, privileged-port rule); amended
  2026-10-18 by ADR 0081 (`demo` phase) and ADR 0082 (phone HTTPS flags, `lan_address` check, `fsa tunnel`)
- Date: 2026-10-18

## Context
Plan unit U5 (PLAN M9-T08): `fsa install` (local mode), `doctor`, `status`, `logs`, `version`, `uninstall` and
`login-link` on the base of ADR 0066 (gateway, state, lock, journal, envelope), ADR 0069 (renderer) and ADR 0070
(install.sh, shim, host facts), with the app-side one-offs of ADR 0068. Requirements: BUILD_PROMPT_V2 §7, R9–R15,
R26–R28, R44, R45, R49, R50, R54, R63, R69, KTD1–KTD7, KTD11, KTD13, KTD21, KTD25; AE3, AE4, AE5, AE9.

## Decision
**Install phases.** `Install\Installer` runs under `Supervisor` (lock + journal, ADR 0066), each phase journaled
before it starts:

| phase | what | changes |
|---|---|---|
| `doctor` | re-verify the manifest (`ManifestVerifier`, before any digest is used), run the doctor (which also chooses the ports), refuse a foreign project (exit 7), list orphans | no |
| `secrets` | reuse the secrets in `env/` or generate them (CSPRNG) and write `app.env`/`mailpit.env` at once; an `app.env` with only one of APP_KEY and DB_PASSWORD is exit 3, never regenerated | yes |
| `state` | create or update the state (UUID from the doctor phase, digests, ports), render runtime, env and compose files, `compose config` | yes |
| `pull` | pull every image of the compose file that the engine lacks (exit 3 `image_unavailable`) | no |
| `up` | `compose up --wait` for `postgres` and `redis` only | yes |
| `migrate` | one-off `php artisan migrate --force` | yes |
| `app_install` | one-off `app:install --owner-link --json` (organization, owner, **operator grant**, 60-minute owner link) | yes |
| `start` | `compose up --wait --remove-orphans` for the whole stack | yes |
| `smoke` | see below | no |
| `done` | mark the state `installed` (history entry with the inputs) and make sure a sign-in link goes out | yes |

Deviations from the plan's list: the operator grant is part of `app_install` (`app:install` grants it, ADR 0068),
and the stack starts in two steps (`up`, then `start` after the migrations), because the app roles' healthchecks
and sessions need the schema; starting them before `migrate` risks an unhealthy `up --wait`.
(Amended 2026-10-18: the lock heartbeat is written before and after every phase (`OperationRun`, best effort),
so `fsa status` (`data.lock.heartbeat_at`) shows a live holder also during long steps such as `up --wait` and
`migrate`.)

**Resume rules.** A failure in `doctor` changes nothing: exit 3 (10 for a too old engine, 7 for a foreign
project, 2 for bad input) and the journal is archived. A later failure keeps the journal open (exit 4
`services_unhealthy`, `migrate_failed`, `app_install_failed`, `smoke_failed`; exit 3 `image_unavailable`; exit 2
when `app:install` refuses the input). `fsa install` then resumes with `resume: true`: completed phases are
skipped and return their journaled data (UUID, digests, ports), and the secrets come back from the env files, so
Postgres keeps its first password.
- Inputs: the earlier run's inputs still apply; an explicit flag may change only while the phase consuming it has
  not completed (`--org`, `--owner-email`, `--owner-name`, `--locale` → `app_install`; `--version`, `--port`,
  `--domain` → `doctor`), else exit 2 `flag_already_applied`. Corrections are recorded as a `flags` journal event
  and carried to the next resume.
- Sign-in link: the link exists only in memory, never in the journal. If `app_install` ran in an earlier attempt,
  `done` issues a fresh link with `identity:login-link` (15 minutes) instead of the 60-minute owner link, because
  `app:install` on an installed database answers `already_installed` without a link. If no link can be issued, the
  install still finishes, with a `sign_in_link_unavailable` warning pointing at `fsa login-link`.
- Finished instance (state history has `installed`, no open install journal): explicit flags equal to the
  installation → `changed: false`, no Docker call at all (AE3); any different explicit flag → exit 2
  `use_config_set`. Omitted flags never conflict, and nothing is prompted. The check is repeated once the lock is
  held, so an install that waited for a concurrent one to finish also changes nothing.
  (Amended 2026-10-18 by ADR 0078: `use_config_set` lists `data.flags: [{flag, key|null}]`, the `fsa config set` key
  for each differing flag, and says plainly that proxy mode cannot be entered or re-wired on an installed instance:
  `fsa uninstall` and a fresh install, with the data carried over only from 2.0.0-rc.1 (`--from-backup`);
  `fsa uninstall --keep-backups` writes a final backup first. `--trusted-proxy` matches the trusted proxy by
  container name, trusted address or ID prefix (12+ hex characters, one read-only network inspect), as
  `config set trusted-proxy` resolves it.)
- If `app:install` finds an organization already there (`already_installed`, e.g. a resumed install after a
  one-off that timed out after committing), the result warns `app_already_installed`: the org and owner flags of
  this run were not applied.
- A new install needs `--org` and `--owner-email`: prompted only with a terminal and without `--yes`/`--json`;
  otherwise exit 2 `input_required` (R26, U4's scenario; amended 2026-10-18: was exit 6, which stays reserved
  for confirmations). `--owner-name` defaults to the local part of the email,
  `--locale` to `de` (as `app:install`).

**`--version` reaches fsa as `--release`.** Symfony's console answers a literal `--version` with the framework
version before any command runs (verified: `artisan release:version --version 2.0.0` prints "Laravel Framework"
and exits 0). install.sh passes `--version <v>`, so the in-image `fsa` wrapper maps `--version`/`--version=` to
`--release` for `fsa install` only, up to a literal `--`; `fsa:install --release` checks it against the verified manifest (exit 3
`release_mismatch`).

**Release source.** `FSA_RELEASE_MANIFEST` (install.sh, must point into the instance directory) or, for a re-run
through the shim, `releases/<--version>/` or the only release stored there; else exit 2 `manifest_required`. The
stored digests are `<FSA_REGISTRY or registry>/<repository>@<digest>` for every manifest image, exactly what
install.sh pulled, so the shim's `digests.app` names the image on the host.

**Port selection (R15, AE4).** The doctor's ports check publishes a throwaway container of the app image on
`127.0.0.1:<port>` (`--entrypoint true`, `--rm`, labelled with the instance UUID and role `port-probe`); exit 125
with "port is already allocated", "address already in use" or Docker Desktop's "ports are not available" means
taken, anything else fails the check. The app gets 8080 or the next free port up to 8099, the catcher UI 8025 or
the next free one; an explicit `--port` must be free (exit 3). The choice lives in the journal and the state, so a
resume or a repeated install never probes its own ports again.
(Amended 2026-10-18: `details` has one shape for install and doctor, `{ports: {app, mail_ui}, taken}`, with
`ports` empty when none was found. `fsa doctor` on an instance does not probe a port that the project's own
running container publishes (`app`, `mailpit` per `compose ps`), so a running instance no longer reports its
own ports as taken.)
(Amended 2026-10-18 by ADR 0075 and ADR 0076: in domain mode the check probes 80 and 443 for the edge
(`EdgePorts`), and `details` is `{ports: {http, https}, taken, rootless}`. In proxy mode on a shared network the app
publishes no port, so `ports` has no `app` entry.)
(Amended 2026-10-18 by ADR 0078: an install on a shared proxy network chooses only the catcher UI's loopback port,
so a taken 8080–8099 no longer stops it. A refused probe counts as `privileged_ports` only in RootlessKit's wording
(`privileged port`, `ip_unprivileged_port_start`, `rootlesskit`) or as "permission denied" on a host-wide probe
below 1024; any other refusal is `port_probe_failed`.)

**Doctor checks** (`Doctor\Checks`, in this order; results in the shape of the platform's `HealthResult` plus a
hint; probes behind `DoctorProbes` and `PortProbe`): `docker` (Engine ≥ 24.0, Compose ≥ 2.24; too old is exit 10),
`architecture` (linux/amd64 or linux/arm64, or the manifest's platforms), `memory` (engine MemTotal: < 1536 MiB
fail, < 1800 MiB warn), `disk` (free space of the instance directory: < 4 GiB fail, < 10 GiB warn), `path_sharing`
(the launcher's nonce was seen), `permissions` (directory, `state.json`, `compose.yaml`, `fsa`: not group- or
world-writable and owned by the in-container owner UID of ADR 0070: host UID on rootful, 0 on rootless and
Desktop), `stale_files` (removes `.fsa-nonce-*`/`.fsa-open-url-*` older than an hour, never the current run's),
`dns` (domain only), `registry` (HEAD `https://<registry>/v2/` for registries of images the engine lacks; loopback
mirrors are left to the pull; unreachable warns), `clock` (skew against that response's `Date` header: > 30 s
warn, > 300 s fail, no reference skipped), `ports`, and with `--post` `services` (every compose service running,
healthy where it has a healthcheck). Any failure is exit 3 with all results under `data.checks`; exit 10 only for a version
below the floor (an unreadable `docker version` is a plain failure).

**DNS and AE5.** A and AAAA are resolved separately with the container's resolver and compared with
`FSA_LAN_ADDRESSES`. No record, a record pointing at a non-public address that is not the host's, or an A record
elsewhere while the host has a public IPv4 fail; a host with only private addresses warns (NAT, cannot tell); a
public AAAA warns (the host's IPv6 addresses are unknown), a non-public AAAA fails. Domain mode itself is U8: an
install with `--domain` runs the whole doctor phase (AE5: exit 3 before any service starts and before any pull but
the cosign image, which install.sh already pulled) and, when it passes, stops with exit 2 `unsupported_mode`.
Other later flags (`--behind-proxy`, `--url`, `--phone-https`, `--demo`, `--encrypt`, `--from-backup`) are
recognised and refused with exit 2 `unsupported_option`.
(Superseded 2026-10-18: `--domain` installs domain mode (ADR 0075), and `--behind-proxy` with `--url` installs
proxy mode (ADR 0076). Neither stops with `unsupported_mode` or `unsupported_option` any more; the other later
flags are still refused with `unsupported_option`.)

**Smoke path.** The ops container is not on the instance's networks, and on Docker Desktop a published port is
reachable only on the Mac side, so the smoke test runs `curl` from the app image in a throwaway container joined
to `<project>_default` (`Host: localhost:<port>`, the name the app's trusted-host list accepts):
`http://app:8080/up` and `/admin/login` (needs the database and the session table), with retries; and a second
one on `<project>_renderer` for Gotenberg's `/health`. Both are labelled with the instance UUID (role `smoke`).
PDF render and test mail (R11) join with the capture-mode unit (U12).

**Sign-in link delivery** (`Lifecycle\SignInDelivery`, install and login-link): `--secrets-file` (0600) when
given; else the shim's browser hand-over when the host has a desktop session (the agent never sees it); else the
envelope's `secrets`. `data.sign_in_link` says which, with the expiry. The link never reaches data, warnings,
errors, the journal or progress (the redactor knows it as soon as it exists).
(Amended 2026-10-18: a desktop session alone no longer means the browser: only `--open`, or a person at a desktop
terminal without `--json`; otherwise `secrets` or `--secrets-file`, see ADR 0070.)

**Other commands.** `status` is read-only (it starts only the socket-less `identity:login-links --unused` one-off
while Postgres runs, and passes on only known fields of its rows, never a token or URL). `logs` validates services against the compose file and redacts env secrets. `version` needs
no instance, database or Docker. `uninstall` takes the lock and removes by label only (containers forced, then
networks, then volumes; never through `compose down`, which would act on a same-named foreign project), then the
directory's files (all but `backups/` with `--keep-backups`, and the running launcher's nonce, which the shim
removes; the marker goes too, so install.sh accepts the directory later), and only then releases the lock and
removes the lock directory. The UUID comes from the state, else (missing or unreadable state) from an install
journal, the compose file's labels or the containers that ran from the directory; without one only the files go,
with an `instance_id_unknown` warning. The directory itself stays empty: it is the ops container's bind mount. If a resource cannot be
removed, the files stay and the command exits 1, so it can run again. `--orphans <uuid>` removes only that other
installation's labelled resources. (Superseded 2026-10-18 by ADR 0073: `--keep-backups` first writes a final
backup (dump, storage archive, APP_KEY); `--orphans` accepts only an orphan install reported for this directory
and refuses a live one with exit 7 `orphans_live`; an unknown UUID with labelled resources still tied to the
directory is exit 7 `instance_id_unknown` and deletes nothing; a partial uninstall is exit 4
`uninstall_incomplete`, retryable, with `changed: true` once something was removed or backed up.) `login-link` maps the app's
errors: `user_not_found`, `not_admin`, `inactive`, `sso_required`, `invalid_input` → 2; `rate_limited` → 9
(retryable); no result → 4 `one_off_failed` or `one_off_timeout` (retryable; amended 2026-10-18: was 1, the
internal-error code of ADR 0066).

## Alternatives considered
- **Starting the whole stack before `migrate`** (the plan's order): one phase fewer, but the app's health and
  session table depend on the schema.
- **Smoke test through the published port from the ops container:** unreachable on Docker Desktop and couples the
  check to the host's network.
- **Binding probes to `0.0.0.0` or reading `/proc/net/tcp`:** the ops container sees neither the host's sockets
  nor Docker Desktop's forwarded ports; publishing through the engine is the only answer that matches `compose up`.
- **Removing resources with `compose down -v`:** acts on whatever carries the project name.
- **A 60-minute owner link on resume:** needs an app-side change to `app:install` (`already_installed` issues none);
  recorded as a review question.

## Consequences
- J1 and J12 run real installs against the harness; the bootstrap journey uses the real `fsa install`/`doctor`
  for exit 2 `input_required` (amended 2026-10-18: was exit 6) and the nonce check and keeps the probe for the
  hand-over contract. One harness serves all three (`SharedHarness`).
- U8 lifts `unsupported_mode` for `--domain` (the DNS check is in place) and adds the edge to the smoke path; U11
  provides the `fsa config set` the exit-2 hint names; U12 adds PDF and mail to the smoke test; U16 fills
  `last_backup`.
- Port probes leave no container behind (Docker removes a `--rm` container whose start failed; uninstall sweeps
  by label anyway).

## Amendment 2026-10-18: the `demo` phase (ADR 0081)
`--demo` is no longer `unsupported_option`. With it the install runs the phase `demo` (and `demo_rotate` when an
existing demo needs new passwords) after `start` and before `smoke`, against the running stack, as `fsa demo` does.
Both run again on a resume, since their one-offs are idempotent; an existing demo whose random passwords were never
shown gets new ones (`demo_passwords_reissued`). `--demo` may be added on a resume. On a finished instance the state
history's `demo_seeded` decides: `--demo` with demo data is `changed: false`, without it exit 2 `use_config_set`
with the hint `fsa demo`.

## Amendment 2026-10-18: phone HTTPS at install, the `lan_address` check, `fsa tunnel` (ADR 0082)
- `--phone-https local-ca|tunnel` and `--lan-address` are accepted in local mode (with `--domain`/`--behind-proxy`,
  or `--lan-address` without `local-ca`: exit 2 `invalid_combination`); only `--encrypt` and `--from-backup` are
  still `unsupported_option`.
- `local-ca` is decided and journaled in the doctor phase (no known LAN address: exit 2 `lan_address_required`); the
  ports check also probes 80/443 on the LAN address (`LocalCaPorts`) and the smoke test checks the edge
  (`LocalCaEdge`). `tunnel` installs in local mode, then starts the tunnel (`InstallTunnel`); a failed tunnel keeps
  the instance and the command exits with the tunnel's code.
- Doctor checks gain `lan_address` after `ports` and `proxy` (local-CA mode: warns `lan_address_changed`). New command
  `fsa tunnel start|stop|renew`; `FSA_TEST_TUNNEL` (harness images only) swaps in the journeys' fake tunnel.

## Amendment 2026-10-18: PDF render and test mail in the install smoke test (R11)
U12 did not add them (the smoke path above and the U12 consequence promised it); M11-T05 does. The `smoke` phase of
`fsa install` now runs `Install\InstallSmoke`: SmokeTest's HTTP checks unchanged (`/up`, `/admin/login`, Gotenberg's
`/health`, the edge or the local CA), then, each in a socket-less one-off of the app role (KTD25):
- **PDF** — `instance:pdf-test` renders one page through the app's own `GotenbergClient` (the path every rendition takes,
  over the internal renderer network) and checks that the answer starts with `%PDF-`. Failure: exit 4
  `pdf_render_failed`, retryable, `data.output` with the one-off's message.
- **Mail** — `instance:mail-test --to=<owner> --install` sends a test mail synchronously through the instance mailer:
  into the bundled catcher in capture mode, through the server of `--smtp-host` in SMTP mode (ADR 0080 amendment). The
  install variant has its own wording (`capture.test_mail.install_capture|install_smtp`, DE/EN; the subject is the
  `config set mail` one) and its own Message-ID (`<32 hex>@<sender domain>`, `data.message_id`). Refused: exit 4
  `test_mail_failed`, retryable. In capture mode a throwaway `curl` container of the app image on `<project>_default`
  (label role `smoke`) then asks `http://mailpit:8025/api/v1/messages?limit=50` (`/_mail/api/…` in domain mode,
  MP_WEBROOT) for that Message-ID: Mailpit's message summaries carry `MessageID` (verified against the Mailpit API
  reference, Context7 /axllent/mailpit, 2026-10-18); the list was preferred over `/api/v1/search?query=message-id:…`,
  which exists too, to depend on no search grammar. The catcher login reaches curl as `--config -` on stdin, never on a
  command line. Up to 5 lookups, 1 s apart; then exit 4 `test_mail_not_captured`, retryable.
- Every failure leaves the journal open at `smoke`, like `smoke_failed`; a resume runs the whole smoke test again (and
  sends another test mail). The phase's maximum grows by 120 s (PDF), 120 s (mail) and, in capture mode, 5 × 31 s.
- The owner's address stays out of exception messages (journaled by `failPhase`) and phase data (archived journals
  replace only the `owner_email`/`owner_name` keys); a one-off's error output names it `<owner-email>`.
- **Install only.** `fsa config set` verifies with SmokeTest alone (phase `verify`): a settings change neither renders
  nor mails, so an operator does not get a test mail for every port or domain change, and `config set mail smtp` keeps
  its own test mail (ADR 0080). Rejected: the same checks in `verify` (mail noise, and 2–4 more one-off containers in
  every rollback window).
- Verified by `tests/Unit/Instance/InstallSmokeTest.php`, `tests/Feature/Instance/InstallSmokeCommandsTest.php`, and
  J4 (the capture install's test mail in the catcher; an SMTP install's at a stand-in server).

## Amendment 2026-10-19: resuming an install with `--phone-https tunnel` (M11 gate)
- The doctor phase journals `phone: {phone_https: tunnel}` for `--phone-https tunnel` (the state does not take it), so
  a resume with the same flags no longer exits 2 `flag_already_applied`. `fsa install` decides whether to start the
  tunnel from the effective request (`Installer::effective`: the journal's inputs under the flags given now), so a
  resume without the flag starts it too, as `--demo` given once still applies.
- On a finished instance `--phone-https tunnel` matches an instance with a running tunnel or without phone HTTPS (the
  tunnel is transient, ADR 0082 amendment): same flags are `changed: false` with the warning `tunnel_not_running`
  naming `fsa tunnel start`; it was exit 2 `use_config_set` with the key `phone-https`, which `config set` refuses.
- Verified by `tests/Unit/Instance/TunnelInstallTest.php`.

## Amendment 2026-10-19: backups in install and status (U16, ADR 0084)
- The `migrate` phase also sets up the backup service's database role (`instance:backup-role`, a socket-less one-off,
  the password by name), idempotent like the migrations; a failure is exit 4 `backup_role_failed`, retryable.
- The `state` phase records the host's time zone (FSA_TZ) as the setting `time_zone`, the "instance time" of the backup
  schedule.
- `fsa status` fills `last_backup` (the newest successful backup run) and adds `backup` (schedule and next run,
  encryption, backups on disk, safety backups with age and size, alerts), read from the run ledger without a database;
  failed backups, scheduled runs skipped for more than 24 hours and a schedule without success for 26 hours are warnings.
  The services list includes `backup`.

## Amendment 2026-10-20: `fsa reset` in status (U23, ADR 0088)
- `fsa status` adds `last_reset` and `reset` (`last`, `interrupted`: last phase, whether the database was recreated, the
  safety backup or audit export, the command that resumes it). The `operation_unfinished` warning and exit 9 of other
  commands name `fsa reset` with its flags (`data.command`, `data.options`).
- The image's `fsa` wrapper lists `reset` among the commands.
- `app:install` creates the permission catalog, the owner, the organization, the operator grant and the owner link in
  one transaction (U23 second review A7): a failure leaves none of them, so a repeated install or `fsa reset` never takes
  an owner without the operator role for a finished install.
