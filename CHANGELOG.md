# Changelog

All notable changes to this project are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses
[Semantic Versioning](https://semver.org/) (0.x: the API and data model may still change).
The Form Schema Spec has its own changelog in `spec/form-definition/1.0/CHANGELOG.md`.

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

