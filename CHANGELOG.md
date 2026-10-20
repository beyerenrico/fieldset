# Changelog

All notable changes to this project are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses
[Semantic Versioning](https://semver.org/) (0.x: the API and data model may still change).
The Form Schema Spec has its own changelog in `spec/form-definition/1.0/CHANGELOG.md`.

## [Unreleased]

### Added
- **Backups** — every instance runs a `backup` service (the app image, container root with four capabilities, no
  Docker socket, the storage volume read-only) that backs up daily at 03:30 in the instance's time zone and keeps the
  newest 7 scheduled backups in `backups/`. A backup is the database dump, the storage volume, the portable settings,
  the audit chain heads and a manifest, all read from one database snapshot, with `SHA256SUMS` and an HMAC keyed from
  APP_KEY; it counts only once the dump reads back. Retention prunes only scheduled backups: manual, kept and safety
  backups stay (an upgrade's safety backup is replaced by the next committed upgrade's; restore, reset and pre-rollback
  safety backups stay until you delete them), and a run that would fill the disk is skipped (ADR 0084).
- **`fsa backup`** — a manual backup now, while the instance keeps running; `--include-secrets` adds APP_KEY and the
  SMTP account (encrypted backups only), `--encrypt` turns encryption on first (ADR 0084).
- **Encrypted backups** — `fsa config set backup-encryption on --identity-out <path> --passphrase-file <file>` (or
  `--passphrase-stdin`) creates an age key pair, writes the identity encrypted with your passphrase outside the
  instance directory and checks that it decrypts; only the public key stays in the instance, and every later backup
  is one age file (a safety backup is sealed once its operation completed). `off` switches back (ADR 0084).
- **`fsa config set backup-schedule <HH:MM|off>` and `backup-retention <n>`** (ADR 0084).
- **Backup status** — `fsa status` shows the last backup, the schedule and its next run, the backups on disk and the
  safety backups with their age, and warns when the last backup failed, when a scheduled run was skipped for lack of
  disk space, when scheduled runs were skipped for more than a day because an operation held the lock, when the backup
  service cannot start, when no backup succeeded within two hours of a scheduled slot, or when an interrupted backup
  left a `.partial` directory; the `/platform` health page has a backup check with the same alerts, so it also notices
  a backup service that is not running (ADR 0084).
- **Audit chain epoch** — `audit:verify --heads` reports the chain epoch with the heads (ADR 0084).
- **`fsa restore <backup>`** — restores a backup into the instance after confirmation: the backup is decrypted and
  checked in a container without network and Docker socket (checksums, an HMAC verified only with APP keys from outside
  the archive, every archive member and every object of the dump judged before anything is written), the instance is
  quiesced behind its gate, the final audit heads go to `backups/ledger.log`, a safety backup is taken, and the backup
  is restored into a scratch database and a fresh storage volume. Security changes made since the backup (revoked
  devices, deactivated or erased users, changed passwords, e-mail addresses and MFA, deleted API tokens, SSO identities
  and providers, revoked operators, roles, group memberships and form access, deleted organizations and webhook
  endpoints) are re-applied, sessions, reset tokens and sign-in links are cleared, numbering never goes backwards, the
  audit log starts a new epoch with an `instance.restored` entry, and only a restored instance that passes its checks
  behind the gate replaces the old database and volume; otherwise the old instance runs again (exit 5). Only backups of
  the same instance are restored in place (another instance's go to a new directory with `--from-backup`); webhooks stay
  paused afterwards until you run `fsa config set webhooks on`. When the instance was itself restored after the backup
  was made (undoing a restore with its safety backup), only changes the audit log records are re-applied and the rest is
  reported; restoring a deleted form or group brings back its access grants and members. A backup from a newer version
  is refused with exit 7 and changes nothing (ADR 0085).
- **`fsa restore --drill <backup>`** — restores into a scratch database and reports counts, audit chain heads, missing
  files, a test render and a decrypt sample without touching the instance (ADR 0085).
- **`fsa restore --abandon`** — cleans up a restore that stopped half-way, also after exit 8, and brings the instance
  back; when the previous database or storage volume is gone it continues with the restored data instead (ADR 0085).
- **`fsa install --from-backup <backup>`** — moves an instance to another host: settings that belong to the instance
  come from the backup, host-bound ones from the flags; a domain that does not point here yet is "certificate pending".
  The moved instance starts with captured mail and paused webhooks until you confirm with `fsa config set webhooks on`
  and `fsa config set mail smtp …`. 1.0 backups from `scripts/backup.sh` are accepted with `--accept-unauthenticated`;
  their unsafe settings (debug mode, insecure SSO, trusted proxies) are never applied; backup recipients are kept only
  from an authenticated backup (ADR 0085).
- **`--app-key-file`, `--identity-file`, `--accept-unauthenticated`** for restore and `--from-backup`: APP keys of an
  older or another instance (they become previous keys, so old data still decrypts), the backup identity with
  `--passphrase-file`/`--passphrase-stdin`, and archives without a valid HMAC (ADR 0085).
- **`fsa config set webhooks on|paused`**; a webhook step also waits when the instance's runtime file cannot be read
  (ADR 0085).
- **`fsa upgrade`** — moves the instance to the release marked latest (the most recently published stable release;
  `--to <version>` picks one, pre-releases included, `--major` crosses a major version), health-gated: the target's
  signed manifest is downloaded and verified, every image pulled, the app answers 503 behind its gate while the queue drains
  (`--drop-jobs` saves and drops what a bounded drain leaves) and a verified safety backup is taken, then the target's
  own `fsa` renders its compose and env files and runs its migrations in a container without the Docker socket, its app
  must pass the smoke test behind the gate, and only then is the upgrade committed and opened. Requests that were
  already running when the gate rose finish before the safety backup (a stuck one stops the upgrade with exit 9), and
  the free-space check counts the live database and files. Only the compose, env and service files are taken from the
  target, and nothing in them may reach past the containers (host paths, capabilities, devices, host networking, images
  the release does not pin). Any failure from the safety backup until the commit rolls back automatically: the files the
  target replaced are put back, the safety backup is restored while the previous app answers 503, the cache flushed and
  the previous release started again (exit 5, with the logs); a rollback that fails stops the instance and names the
  backup and both image sets (exit 8). The upgrade runs in a background container of the installed release, so a closed
  SSH session never stops it half-way; `fsa status` shows its phase and `fsa upgrade --resume` continues an interrupted
  one. After a commit the previous pre-upgrade safety backups are removed (never one marked kept); restore, reset and
  pre-rollback safety backups stay until you delete them; safety backups an upgrade leaves behind on its way back are
  encrypted when encryption is on (ADR 0086).
- **`fsa rollback`** — returns to the release before the last upgrade from that upgrade's safety backup, after telling
  you what was written since and will be lost; security changes made since the upgrade (revocations, erasures, changed
  passwords, deleted tokens) are carried over, numbering never goes backwards, and the audit log starts a new epoch with
  an `instance.rolled_back` entry. A rollback that cannot finish goes back to the upgraded release exactly as it was
  (exit 5; exit 9 when nothing was changed, such as a queue that does not drain); an interrupted rollback continues
  with `fsa rollback`, without the question (ADR 0086).
- **`fsa restore` of an older backup** — a backup made by an older release is now restored in place and migrated to the
  installed release, instead of being refused, the backup `fsa adopt` made included (with `--accept-unauthenticated`)
  (ADR 0085 and 0087 amendments, ADR 0086).
- **`fsa adopt <checkout>`** (install.sh `--adopt <checkout>`) — turns a clone-based 1.0 install into an instance by
  copying its data: it stops 1.0's app, scheduler and any other service it added, lets its queue drain, takes a backup
  in the 1.0 format (without the APP keys: its manifest names them by fingerprint), stops 1.0's Postgres cleanly,
  copies both volumes into the instance's own and checks the copy against the backup (row counts, audit chain heads,
  migrations, amcheck on the unique text indexes) before 2.0 starts on `postgres:16-alpine`; a 1.0 started again in
  between stops it. It keeps APP_KEY, the database names and the sender, starts with captured mail and names 1.0's SMTP
  server, infers local or proxy mode from APP_URL and APP_PORT, reads only an allow-list of the 1.0 `.env` (which must
  match what 1.0 runs with) and never applies its debug, SSO or trusted-proxy values. A Compose override, an external
  database or a Postgres other than 16 on Alpine is refused (exit 7). Any failure before 2.0 starts brings 1.0 back
  unchanged and leaves nothing of 2.0 behind (exit 5); `--abandon` undoes an adopt that stopped half-way (ADR 0087).
- **`fsa reset`** — returns the instance to a fresh state after confirmation: the organization and owner it was
  installed with (the owner gets a new one-time sign-in link), and with `--demo` the demo data (random passwords
  whenever the instance is reachable beyond loopback). The instance is quiesced behind its gate, the final audit chain
  heads go to `backups/ledger.log`, a safety backup is taken (never pruned), then the database is recreated, the audit
  log starts a new epoch with an `instance.reset` entry naming the old heads, files go to a fresh storage volume, and the
  cache, queues and captured mail are emptied. Settings and secrets stay. `--no-backup` skips the safety backup but still
  writes the whole audit log to `backups/audit-export-reset-<time>.jsonl` while a real organization exists. A failure
  before the database is recreated leaves the instance as it was (exit 5); a reset stopped after that is finished by
  running `fsa reset` again, without a question. Row IDs of the old data are never handed out again, so signed links
  sent before the reset (invitations, data exports) find nothing; leftover restore databases and storage volumes go too
  (ADR 0088).
- **`fsa reset --abandon`** — ends a reset that stopped before it recreated the database and starts the instance again
  as it was (ADR 0088).

### Changed
- `fsa uninstall --keep-backups` writes its final backup in the 2.0 backup format (`backups/final-<time>`, encrypted
  when backup encryption is on), so `fsa install --from-backup` and `fsa restore` read it; the instance is quiesced
  first, so no write is lost after the snapshot, and a failed backup starts it again. The APP keys go inside an
  encrypted backup, or beside a plain one (`final-<time>.app-key.env`, named in a warning). The proxy-mode hint now
  says how to carry the data over (ADR 0073 amendment).
- Backups resist whoever else can write into `backups/` (a sync tool, say): a backup being written stays root's until
  it is complete, `SHA256SUMS` lists what the backup service produced, and a file added or changed meanwhile fails the
  run instead of being authenticated. The service's settings and every plaintext working copy (the plain set of an
  encrypted backup, a restore's decrypted set, the rollback's security export, reset's audit export) live in the new
  private `staging/` directory of the instance, never in `backups/` (ADR 0084, 0085 amendments).
- `fsa restore`, its drill, `fsa rollback`, an upgrade's way back and `fsa install --from-backup` copy a plain backup
  into the instance's private `staging/` and check and restore only that copy, so a change in `backups/` after the
  check (a sync tool, say) never reaches the restore; such a restore needs free space for one more copy of the dump and
  the storage archive (ADR 0085 amendment).
- `fsa uninstall` has the backup service remove what a backup run that was cut off left behind (root's partial sets,
  possibly a plaintext dump), and names any file it still cannot remove instead of reporting data and backups deleted;
  a failure while it quiesces for the final backup starts the instance again and lowers the gate (ADR 0073 amendment).
- `fsa status` and `/platform` warn when the newest backup misses files the database references, and retention keeps
  the older backup that missed the fewest files for as long as new ones miss them (ADR 0084 amendments).
- `fsa restore` refuses a backup without files unless `--without-files` (the replaced storage volume is then kept), and
  refuses a backup renamed to another backup's name; `fsa rollback` refuses a safety backup that is no longer the set
  the upgrade verified (ADR 0085, 0086 amendments).
- `fsa install --from-backup` lists the backup's encryption recipients and keeps them only with `--keep-recipients`
  (ADR 0085 amendment).
- `fsa reset --no-backup` encrypts its audit export to the backup recipients when encryption is on (ADR 0088
  amendment).
- Containers fsa starts outside the stack run with `no-new-privileges`; those that read a backup or a 1.0 install drop
  all or nearly all capabilities and run on a read-only root file system (ADR 0085, 0087 amendments).
- `fsa uninstall` of an adopted instance (also one whose adopt stopped after 2.0 started) takes a final backup by
  default and keeps `backups/`; it reports the writes made since the adopt, whether 1.0 ran since then, and how to start
  1.0 again (`docker compose up -d` in its checkout) with the jobs 1.0 will then run; `--no-backup` skips the final
  backup (ADR 0087).
- `fsa status` and `fsa logs` list the `backup` service; the stack file for Portainer and Coolify still has no
  backup service (ADR 0084).
- `fsa status` shows the last restore, the storage volume in use, paused webhooks and an interrupted restore with the
  commands that resume or abandon it; `fsa config show` shows the storage volume, the webhook state and the last
  restore (ADR 0085).
- `fsa status` shows the last reset and an interrupted reset with the command that finishes it (ADR 0088).
- `fsa backup` and `fsa login-link` refuse while another operation is unfinished (exit 9), and a restore of a safety
  backup taken before a reset carries no security state from the reset instance (ADR 0084, 0085, 0088).
- Backups list the fingerprint of every APP key, previous keys included; a backup verifies even with stray dotfiles
  such as `.DS_Store` beside it, and file names in `SHA256SUMS` must be plain names (ADR 0085).
- `fsa restore` refuses a backup older than the installed release's oldest upgrade source (exit 7), as documented; the
  check never applied before (ADR 0085 amendment).
- `fsa restore --accept-unauthenticated` also refuses an archive that lacks objects this release's migrations create at
  the archive's own migration level, such as the audit log's append-only triggers (ADR 0085 amendment).
- `fsa status` shows rollbacks as the last restore and no longer warns about a restore's scratch directory while an
  upgrade, rollback or reset runs (ADR 0085 amendment).
- Every JSON envelope carries the numeric `exit_code` (also those of install.sh, the shim and the image's `fsa`), and exit 6
  `confirmation_required` carries the facts its question names under `data` (a rollback's versions, safety backup and
  loss counts; restore, reset, adopt, uninstall).

### Fixed
- Hints that sent you to `fsa install --from-backup … --dir <new directory>` (which the instance's `fsa` refuses for
  another directory) now name `install.sh --dir <new directory> --from-backup <backup>`; `fsa restore --abandon` hints
  carry `--yes` (ADR 0085 amendment).
- `fsa install --from-backup` lists `<dir>/fsa config set webhooks on` among its next commands (it printed `fsa` twice),
  and `fsa reset` names the instance's own `<dir>/fsa` in its next commands.
- A failed audit seal during `fsa rollback` names `fsa rollback` to run again, not `fsa upgrade`; a missing backup
  service and a backup service that cannot start name `fsa config set` (which renders the files again) instead of
  `fsa install`, which changes nothing on an installed instance.
- A plain `fsa install` no longer takes over an unfinished `fsa install --from-backup` (it skipped the restore steps and
  marked an empty instance installed): it stops with exit 9 and names the `--from-backup` command to run.
- Every resume command (`fsa status`, exit 9 `operation_unfinished`) names all the flags the resumed run needs: the
  backup, `--identity-file`, `--passphrase-file <path>` or `--passphrase-stdin`, `--app-key-file`,
  `--accept-unauthenticated`, `--without-files`, `--keep-recipients`, and `--yes` where the command asks first. A resumed
  restore, rollback or install from a backup without one of them stops with exit 2 before it changes anything, instead of
  going back.
- `fsa upgrade --resume` with nothing to resume stops with exit 2 `nothing_to_resume` instead of starting a new upgrade,
  and a resumed upgrade keeps the `--to` it began with. The upgrade's result survives a closed terminal: `fsa upgrade
  --follow` follows a running upgrade or shows the last result, and `fsa status` shows it as `last_upgrade`.
- `fsa status` tells a running operation from an interrupted one (`operation.state`), shows the upgrade supervisor
  (`supervisor`) and whether the lock's holder is gone (`lock.stale`).
- Ctrl-C or a closed SSH session during `fsa restore`, `fsa rollback` or `fsa adopt` stops at the next step boundary and
  goes back (after adopt started 2.0: stops with the journal open), instead of killing the command half-way.
- `fsa rollback` leaves webhooks paused like a restore (runs restored from the safety backup could repeat deliveries);
  confirm with `fsa config set webhooks on`.
- A safety backup that an interrupted restore or reset wrote before it recorded its name is encrypted too.
- `fsa restore --drill` refuses while an operation is unfinished; `fsa restore`, `fsa reset` and `fsa rollback` report an
  unfinished operation before they ask or count anything; `fsa login-link`, `fsa upgrade` and the drill report an
  unfinished install or adopt instead of "not installed"; `fsa login-link` works while a backup runs.
- A restore never starts the quick tunnel again; a resumed restore or rollback that finds the app or workers running takes
  its heads and safety backup again.
- install.sh refuses `--passphrase-stdin` like `--password-stdin` (its stdin is the script); an empty passphrase is
  `passphrase_missing`, not `weak_passphrase`.

## 2.0.0-beta.1 — 2026-10-19 (local pre-release, not published)

Mail, demo data and phones (M11): capture mode is visible and can be switched to a real SMTP server, demo data
comes with random passwords, phones get HTTPS through a local CA or a quick tunnel, and the E16 floor is measured.

### Added
- **Capture banner** — while the instance captures mail, every admin page of an organization says so: the full
  banner for organizations on the instance mailer, a narrower one (invitations, password resets and email address
  confirmations) for organizations with their own SMTP server. Operators see the `fsa config set mail smtp`
  command, everyone else "ask your instance owner" (ADR 0080).
- **`fsa config set mail smtp|capture`** — switches to an SMTP server after a test mail and reverts when the
  server refuses it; the password comes from `--password-stdin` or `--password-file`, never from a flag. The mail
  catcher is kept, receiving nothing, until `--remove-catcher`; `capture` switches back. The result counts the
  invitations and password resets that capture kept from people (ADR 0080).
- **`fsa config set operator-email <email> [--revoke-previous]`** — makes an address the instance operator,
  creating the account and a sign-in link when needed (ADR 0080).
- **`fsa config show --catcher-login`** — the mail catcher UI's password in local and proxy mode, as a one-time
  secret (ADR 0080).
- **`fsa install --smtp-*`** — `--smtp-host`, `--smtp-port`, `--smtp-encryption starttls|tls|none`, `--smtp-username`
  with `--password-file` or `--password-stdin`, `--from-address` and `--from-name` install straight into SMTP
  mode, without a mail catcher. `starttls` (the default) refuses a server that offers no STARTTLS before any
  credentials are sent; `none` is for a relay without TLS (ADR 0080).
- **Demo data** — `fsa install --demo` adds the demo organization, forms, workflow and users; `fsa demo` adds them
  to an installed instance and hands out the demo owner's sign-in link; `fsa demo --rotate-passwords` gives every
  demo user a new random password and revokes their sessions and API tokens. Demo passwords are random (listed
  once) whenever the instance is reachable beyond loopback (ADR 0081).
- **Phone HTTPS** — `fsa install --phone-https local-ca|tunnel` with `--lan-address`. The local CA serves the
  instance over HTTPS on the LAN address, with a trust page (`/phone-trust`: root certificate, SHA-256
  fingerprint, iOS and Android steps, QR code, trust check). `fsa tunnel start|stop|renew` runs a Cloudflare quick
  tunnel for 4 hours. `fsa config set phone-https local-ca|off` and `fsa config set lan-address <ip>` change it
  later (ADR 0082).

### Changed
- **Install smoke test** — `fsa install` also renders a PDF and sends a test mail (into the catcher, or through
  the SMTP server) before it reports success (ADR 0072).
- **E16 measured** — the `small` memory budget is retuned from a measurement at the 1.0 test volume inside 2 GB
  and two CPUs, with one default Horizon worker on `small`. Install, PDF and API targets are met; the admin-page
  target (p95 < 500 ms) is missed at 100k submissions on a 2 GB host: saved reports, explorer text searches and
  the reviewer inbox take up to 1.9 s (ADR 0083).

### Not yet in this pre-release
- Backups, restore, upgrade, adopt and reset (M12); the `/platform` instance page and the update check (M13).
- The guides in `docs/guide/` still describe the 1.0 checkout install until M13 rewrites them; `fsa <command>
  --help` lists each command's flags.

## 2.0.0-alpha.2 — 2026-10-18 (local pre-release, not published)

Modes (M10): domain mode with automatic HTTPS, proxy mode behind an existing reverse proxy, a stack file for plain
Docker Compose, and `fsa config` to show and change settings.

### Added
- **Domain mode** — `fsa install --domain <host>` runs a Caddy edge that gets its certificate over ACME (Let's
  Encrypt by default; `--acme-staging`, `--acme-ca` and `--acme-ca-root-file` for another directory), redirects
  HTTP to HTTPS and is the only service that publishes ports (80, 443, 443/udp). The mail catcher is at `/_mail/`
  for operators signed in to `/platform` with MFA (ADR 0075).
- **Proxy mode** — `fsa install --behind-proxy traefik|caddy|port --url <origin>` puts the app behind an existing
  Traefik or caddy-docker-proxy (`--proxy-network`, `--trusted-proxy`), or on a loopback port for any other proxy.
  The app trusts exactly one proxy address (ADR 0076).
- **Stack file** — a Compose file configured only through environment variables, listed in the signed release
  manifest, for plain `docker compose` (R18, ADR 0076; import into Portainer and Coolify is not yet verified).
- **`fsa config show`** — the effective settings with secrets redacted, and what each opt-in feature contacts
  (ADR 0077).
- **`fsa config set <key> <value>`** — `domain`, `url`, `port`, `trusted-proxy`, `mode local`, `acme-email` and
  `acme-ca`, under the instance lock: preflight, apply, verify, and a revert on failure (exit 5 `rolled_back`;
  exit 8 when the revert fails too). A URL change asks for confirmation (`--yes`) and lists the organizations with
  SSO that it affects (ADRs 0077, 0078).

### Changed
- **Runtime state in the app** — the app reads its public URL, trusted hosts and maintenance gate from the
  instance's `runtime.json`, so a URL change reaches requests, queued mails and jobs without a worker restart
  (ADR 0074).
- **Migrations** — run under a lock (`migrate --isolated`); invalid indexes left by an interrupted concurrent
  index build are rebuilt.

## 2.0.0-alpha.1 — 2026-10-18 (local pre-release, not published)

The first 2.0 pre-release: the app is distributed as signed images with the self-managing `fsa`
command, for a local install with one command. It was built and tested locally against a local registry and release server (E18);
publishing it is a human step described in `RELEASING.md`.

### Added
- **Signed multi-arch image** — one image for linux/amd64 and linux/arm64 (about 500 MB uncompressed)
  that runs the app, queue, scheduler and `fsa` ops roles; every base image pinned by digest (ADR 0065).
- **Release pipeline** — a `v*` tag builds the image with SBOM and provenance, signs the image, the
  release manifest and `install.sh` keylessly with cosign, scans every image with Trivy, and publishes a
  signed release manifest that pins every service image by digest per platform (`release:manifest`,
  `release:version`; ADR 0067).
- **`install.sh` and the `fsa` shim** — the installer verifies the signed manifest before it pulls
  anything, pulls the app image by digest and hands over to `fsa install`; `<dir>/fsa` runs every command
  in the instance's ops container (ADR 0070).
- **`fsa` commands in local mode** — `install`, `doctor`, `status`, `logs`, `version`, `uninstall` and
  `login-link`, each with a JSON envelope (`--json`), NDJSON progress and fixed exit codes. `fsa install`
  runs journaled phases, resumes after a failure and changes nothing on an identical re-run (ADR 0072).
- **One-time sign-in links** — the owner gets a 60-minute link after the install; `fsa login-link`
  issues a 15-minute single-use link for an existing admin, stored hashed and rate-limited. A link goes
  to `--secrets-file`, to the browser (`--open`, or a person at a desktop terminal) or into the
  envelope's `secrets` (ADR 0056, ADR 0068).
- **Non-interactive `app:install`** — `--owner-link`, `--password-file` and `--json` create the first
  organization and owner without prompts.
- **Horizon behind the operator session** — `/horizon` opens for platform operators signed in at
  `/platform` (ADR 0068).
- **`DOCKER_DEFAULT_PLATFORM` support** — `install.sh`, the shim and `fsa` install and run a release of
  the other architecture under emulation; `fsa doctor` reports it and memory limits are doubled.

### Changed
- Horizon access needs an operator `/platform` session: `PLATFORM_ADMIN_EMAILS` no longer opens
  Horizon (it still grants `/docs/api`).
- Valkey runs with a password in `fsa` instances (ADR 0069).
- The image is slimmer (2.12 GB → about 500 MB): no Composer, tests, docs or dev dependencies inside.

### Not yet in this pre-release
- Domain and reverse-proxy modes (`fsa install --domain` runs the DNS preflight and stops; added in
  2.0.0-alpha.2), and the capture-mode banner, demo data and phone HTTPS (added since, see above). Backups,
  restore, upgrade and adopt arrive in later milestones.

## 1.0.0 — 2026-10-23

The first stable release: single sign-on and the canvas builder (M7), and hardening (M8) with the audit
log, GDPR tools, security headers, integration tokens and the operator panel. Release notes:
`docs/releases/1.0.0.md`. Upgrade: `docs/guide/upgrade.md`.

### Added
- **Single sign-on** — OpenID Connect (Microsoft Entra ID, Google Workspace, any OIDC provider) and
  SAML 2.0, one identity provider per organization; DNS-verified email domains; email-first login in
  the dashboard and the Field App; just-in-time accounts with claim mappings to roles and groups;
  optional enforcement with a break-glass Owner; hourly provider and daily domain checks with
  notifications (ADR 0036).
- **Canvas builder** — a drag-and-drop builder that edits the same drafts as the classic builder, with
  undo and redo, a full keyboard model and a move menu (ADR 0037).
- **Audit log screen** — "Compliance" → "Audit-Log": read-only, filters by actor, event, subject type
  and date, entry details with redacted secrets, CSV export (queued above 2,000 entries), and a history
  table on forms, submissions, workflows, members and SSO (ADR 0038).
- **Append-only audit log** — a database trigger refuses every UPDATE, DELETE and TRUNCATE on audit
  entries; an unkeyed SHA-256 hash chain per organization, sealed after commit; `audit:seal`,
  `audit:verify` and `audit:prune` with 365 days retention by default (`AUDIT_RETENTION_DAYS`).
  `audit:verify --heads` prints the chain heads; shipped regularly to storage outside the database, they
  provide tamper evidence even against a database owner, who could otherwise recompute the chain.
- **Audit coverage** — every action of the §8.11 list writes exactly one entry, now including failed
  two-factor codes in the dashboard, lockouts, notification preference changes, test mails and webhook
  secret rotations.
- **GDPR tools** — retention per form (delete or anonymize after N months) applied nightly; legal holds on
  forms and submissions with a minimum retention (120 months by default); personal data export as a ZIP
  file and account erasure for admins ("Personal data") and for every dashboard user ("My data")
  (ADR 0039).
- **Integration API tokens** — "Team" → "API-Tokens": personal tokens bound to one organization, with
  abilities per resource, optional expiry, shown once; every authenticated `/api/v1` route declares its
  abilities and refuses tokens otherwise; a rate limit per token; creation, revocation and first use
  are audited (ADR 0043).
- **Operator panel `/platform`** — its own sign-in with required two-factor authentication; organizations
  and members; health of queues, Gotenberg, mail, scheduler and disk; impersonation of admin members
  with a reason, a banner, a time limit and audit entries in the organization's log; every entry written
  while impersonating records the operator (`impersonator`) (ADR 0041).
  Operators are granted with `php artisan app:platform-admin`.
- **Optional malware scanning** of uploads through clamd (`UPLOADS_CLAMAV_*`).
- **Structured logs and error reporting** — a redacting JSON log formatter with the request id, and a
  Sentry-compatible `sentry` log channel without an SDK (`SENTRY_DSN`).
- **Production env template** `.env.production.example`, kept production-safe by a test.
- **Guides** in `docs/guide/`: install, upgrade, backup and restore (with `scripts/backup.sh` and
  `scripts/restore-drill.sh`), configuration, troubleshooting, admin, Field App and API (ADR 0042).
- **Accessibility checks** — axe scans (WCAG 2.2 AA) of the Field App, admin pages, both builders and
  `/platform`, plus keyboard walkthroughs, in the E2E suite.

### Changed
- The canvas builder is the default edit target from the forms list, new forms, the search and the
  dashboard; the classic builder stays one action away and still opens PDF imports with an open import
  report (ADR 0044).
- The PDF of an anonymized submission answers 410 `document_anonymized` in the API and on the signed
  download, and a 410 page in the dashboard; the dashboard and the Field App show a notice and stop
  polling.
- API answers with a 4xx status are no longer reported as errors in the log.
- The API rate limit is configurable (`API_RATE_LIMIT`, 240 per minute) and counted per integration
  token, otherwise per user.
- The image formats stderr logs with `App\Support\Logging\JsonLogFormatter` instead of Monolog's
  `JsonFormatter`.
- Login is email-first in the dashboard and the Field App.
- `compose.yaml` publishes the app port on `127.0.0.1` only; `APP_PORT` is a port number, and the
  reverse proxy runs on the host. `.env.production.example` trusts the Docker bridge range
  (`TRUSTED_PROXIES=172.16.0.0/12`).
- Performance at 100k submissions (ADR 0046, evidence in `docs/agent/milestones/evidence/M8/performance.md`):
  - Saved reports compute the groups and the totals in one query, and the chart renders with the page
    instead of computing the report again: 651 → 365 ms (p95) for a report over all submissions.
  - Explorer searches match the term as one phrase, as exports do, and page without a total while
    searching. "Select all" then selects the visible page; "Export filtered rows" still exports every match.
  - The API submission list loads reviews and open tasks once per page, and a new
    `submissions (organization_id, updated_at)` index serves its order: the reviewer's list 268 → 125 ms.
  - The reviewer inbox computes its filter options once per request: 420 → 316 ms.
  - The Field App evaluates calculated values once per element instead of once per repeater row: typing in a
    500-row repeater 2.8 s → under 170 ms with 4× CPU throttling.

### Removed
- The `AWS_*` lines in `.env.example` (no S3 adapter is installed; they never had an effect).
- The test databases are no longer created by the production `compose.yaml`; only `compose.dev.yaml`
  mounts `docker/postgres/init`.
- spatie's `activitylog:clean` can no longer delete audit entries (the database refuses it); use
  `audit:prune`.

### Fixed
- Reviewers and viewers can load the form version of submissions they may see, so the reviewer's Field
  App detail screen no longer shows an error after approving.
- Duplicate audit entries for invitations of new addresses, account deactivation and publishing a design
  with pending edits.
- The canvas builder's preview frame shows a focus ring (WCAG 2.4.7); empty categories in the forms list
  no longer render an empty link.

### Security
- A Content Security Policy per area with a nonce per request, `Referrer-Policy`, `Permissions-Policy`,
  framing rules and `X-Content-Type-Options` on every response; HSTS and Secure cookies behind HTTPS
  (ADR 0040).
- Admin panel sessions end after 30 minutes without activity and 12 hours after sign-in
  (`config/security.php`); the Field App session is unchanged.
- Trusted proxies (`TRUSTED_PROXIES`; empty trusts no proxy) and a host allow-list (`TRUSTED_HOSTS`):
  forged `Host` or `X-Forwarded-Host` headers get 400, so password-reset and invitation links cannot
  point to another domain.
- The builder preview runs sandboxed on an opaque origin, so a renderer bug cannot reach the admin
  session or the Field App's local data.
- Logs and error reports redact secrets and credential headers; database errors no longer contain query
  values.
- Dashboard avatars are generated locally instead of by ui-avatars.com.

### Known limitations
- One identity provider per organization; no single logout (RP-initiated logout or SAML SLO).
- Web push is deferred past 1.0; notifications are in-app and by email (ADR 0045).
- Screen-reader and real-device accessibility checks still need human testers; handwritten signatures
  need a pointer.
- SSO from the installed iOS app is untested.
- See `docs/releases/1.0.0.md` and `docs/agent/QUESTIONS.md`.

## 0.2.0 — 2026-10-18

Reports and dashboards (M5) and offline-first filling (M6). Release notes: `docs/releases/0.2.0.md`.

### Added
- **Offline Field App** — local-first drafts in IndexedDB (Dexie), an outbox with ordered, idempotent
  sync (create, uploads, update, submit, send), conflict and "needs your decision" handling, a cached
  catalog via the incremental manifest, a service worker app shell, install and update prompts,
  storage persistence, offline start, logout/wipe that clear all local data (ADR 0035).
- **Submission explorer** — dynamic columns across versions, filters, search, per-version currency.
- **Exports** — queued XLSX (repeaters on a second sheet), locale-aware CSV and PDF ZIP with a
  notification; row caps and per-user limits on a dedicated queue.
- **Saved reports** — filters, group-by, count/sum/avg (decimal-exact), table and chart, scheduled
  emails, pinning to the dashboard.
- **Dashboards** — role-aware widgets with a date range and a short per-organization cache.
- **API** — `client_submitted_at` on submit, `retired_version_ids` in the manifest, idempotent customer
  send by key (durable beyond the 24 h replay window).

### Changed
- Deactivating a member's last active membership ends their sessions and wipes their devices; revoking
  a device ends the user's sessions and remember-me token.
- The SPA shell files are always served through Laravel with the CSP.

### Fixed
- The renderer no longer pulls focus back to a newly added repeater row after the user moved on
  (input could land in the wrong field).

## 0.1.0 — 2026-10-17 (MVP)

The MVP (milestones M0–M4, BUILD_PROMPT §4.1). Release notes: `docs/releases/0.1.0.md`.

### Added
- **Organizations and access** — row-level multi-tenancy with a tenant context that survives queued
  jobs; email/password login with verification, password reset and one shared TOTP secret for the
  admin and the Field App; seeded roles (Owner, Admin, Designer, Reviewer, Field Worker, Viewer) with a
  capability matrix; groups; invitations (single use) and CSV bulk invitations; append-only audit log.
- **Form Schema Spec 1.0** — JSON format with meta-schema, roles vocabulary, own JsonLogic dialect
  with decimal and date operators, linter rules and 391 conformance cases run by both SDKs
  (`packages/form-schema-php`, `packages/form-schema-js`, MIT).
- **Form builder v1** — Filament Builder with lossless mapping, logic and calculation editors,
  autosave, lint panel, live phone/tablet preview and PDF preview; versioning with publish, diff,
  rollback (N+1) and retire; JSON import/export; six templates (service report, invoice, inspection
  checklist, delivery note, timesheet, handover protocol); import of fillable PDFs as draft forms.
- **Field App** (`/app`) — React PWA: login with device registration and remote wipe, catalog, fill
  with server autosave and conflict handling, signatures, photos, review and idempotent submit with
  gap-free numbering, submissions with PDF access and "send to customer", open review tasks with
  approve/reject, changes-requested resubmit, notification centre and preferences (DE/EN).
- **Documents** — organization branding, versioned designs, Gotenberg rendering without network
  access, tagged accessible PDFs, immutable renditions with `input_hash` and `file_sha256`.
- **Email** — per-organization sender and optional SMTP override, queued branded mails with a log and
  retry, attachments or expiring links (410 after expiry).
- **Workflows** — triggers (submitted, status changed, approved, rejected, form published, schedule,
  manual), JsonLogic conditions, steps (send email, notify, set status, generate document, webhook,
  request review), idempotent runs with a definition snapshot, retries and visible failures; signed
  webhooks with an SSRF guard; review tasks with SLA reminders.
- **Notifications** — in-app (admin bell, Field App centre) and email, per user, event and channel.
- **API** (`/api/v1`) — documented in `docs/api/field-app-v1.md` and as OpenAPI 3.1 at `/docs/api`.
- **Operations** — Docker images (FrankenPHP, Horizon, scheduler), `app:install`, demo seed, CI with
  static analysis, coverage gates, conformance, license checks and Playwright journeys.

### Known limitations
- Full offline mode (local drafts, outbox, attachment queue) arrives in 0.2 (M6); the API is ready.
- Reports, dashboards and exports arrive with M5; SSO (OIDC/SAML) and the canvas builder with M7.
- See `docs/agent/QUESTIONS.md` for decisions awaiting human review.

