# Changelog

All notable changes to this project are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses
[Semantic Versioning](https://semver.org/) (0.x: the API and data model may still change).
The Form Schema Spec has its own changelog in `spec/form-definition/1.0/CHANGELOG.md`.

## 1.0.0 — 2026-10-19

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

