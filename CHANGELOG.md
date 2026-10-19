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
  APP_KEY; it counts only once the dump reads back. Safety, manual and kept backups are never pruned, and a run that
  would fill the disk is skipped (ADR 0084).
- **`fsa backup`** — a manual backup now, while the instance keeps running; `--include-secrets` adds APP_KEY and the
  SMTP account (encrypted backups only), `--encrypt` turns encryption on first (ADR 0084).
- **Encrypted backups** — `fsa config set backup-encryption on --identity-out <path> --passphrase-file <file>` (or
  `--passphrase-stdin`) creates an age key pair, writes the identity encrypted with your passphrase outside the
  instance directory and checks that it decrypts; only the public key stays in the instance, and every later backup
  is one age file. `off` switches back (ADR 0084).
- **`fsa config set backup-schedule <HH:MM|off>` and `backup-retention <n>`** (ADR 0084).
- **Backup status** — `fsa status` shows the last backup, the schedule and its next run, the backups on disk and the
  safety backups with their age, and warns when the last backup failed, when scheduled runs were skipped for more
  than a day, or when none succeeded for 26 hours; the `/platform` health page has a backup check (ADR 0084).
- **Audit chain epoch** — `audit:verify --heads` reports the chain epoch with the heads (ADR 0084).

### Changed
- `fsa status` and `fsa logs` list the `backup` service; the stack file for Portainer and Coolify still has no
  backup service (ADR 0084).

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

