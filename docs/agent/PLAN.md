# PLAN

Legend: `[ ]` open · `[x]` done (DoD §15 holds) · `[~] blocked: <reason>`.
AC refs point at BUILD_PROMPT sections. Test approach in brackets.

## M0 Foundation (branch `m0-foundation`)
- [x] M0-T01 Laravel 13 skeleton, Pest 5, Pint, Larastan (lvl 8), Rector, `.env.example`, agent-logs ignore — §5, §12 [smoke test, `phpstan`, `pint --test`]
- [x] M0-T02 Docker: Dockerfile (PHP 8.4, qpdf), compose `app queue scheduler postgres redis gotenberg mailpit`; Gotenberg deny-ips + own network + body limit; dev host ports — §11 Operability, §8.5 [compose config test, `/up` check]
- [x] M0-T03 CI (GitHub Actions): lint → static → PHP tests → JS tests → conformance → E2E → image; license check + `composer audit`/`npm audit` — §12, §11 [workflow lint via `actionlint` if available]
- [x] M0-T04 Filament 5 admin panel `/admin`, `Organization` tenant, registration + profile pages, persistent tenant middleware — §6.2, §8.12 [Livewire feature tests]
- [x] M0-T05 Tenancy core: `TenantContext` (scoped) + `Context::addHidden('organization_id')`, `BelongsToOrganization` (throwing scope, auto `organization_id`), `withoutTenant()`, JobProcessing reset of tenant + spatie team id; prove Context restores before model unserialize — §6.2 [unit + queued job tests]
- [x] M0-T06 API base: `/api/v1`, envelope, `X-Organization-Id` middleware (membership check), rate limits, `GET /me` — §10, §6.2 [feature tests]
- [x] M0-T07 Auth: Fortify headless (login, 2FA, reset, verification), Filament login + MFA sharing one TOTP secret with Fortify, rate-limited login, long rolling Field App session config — §8.7, §9.3 [feature tests incl. shared-secret test]
- [x] M0-T08 Organization membership (`organization_user` status/default_group), `groups`/`group_user` — §6.4, §8.6 [model tests]
- [x] M0-T09 Roles: spatie permission teams (team = org), seeded Owner/Admin/Designer/Reviewer/Field Worker/Viewer + permission matrix (done), capability catalog instead of Shield (ADR 0017), `canAccessPanel` keeps Field Workers out, policies — §8.6 [matrix permission tests]
- [x] M0-T10 Audit plumbing: activitylog with org, actor type, IP, UA, request id, secret redaction; auth events logged — §8.11 [feature tests: one entry per action, no secrets]
- [x] M0-T11 i18n DE/EN: lang files, locale resolution (user > org), key-parity test — D7, §11 [unit test]
- [x] M0-T12 Tenant isolation suite: enumerate tenant-owned models (reflection) + API routes; cross-tenant read/write fails — §6.2 [feature test, grows each milestone]
- [x] M0-T13 `app:install` command (first org + owner), demo seeder, `/up` — §11 [command test]
- [x] M0-T14 LICENSE (AGPL + MIT for spec/packages), README, SECURITY.md, CONTRIBUTING — D1, §11 [n/a]
- [x] M0-T16 Members/Groups/Roles Filament resources + policies; invite user by email (signed link, set password) — §8.6, §8.12, journey 1 [Livewire + policy tests]
- [x] M0-T15 Gate M0 (§16.6): green suite, demo screenshots, reviewer subagents, `milestones/M0.md`, merge + tag

## M1 Schema core + Builder v1 (branch `m1-schema-builder`)
- [x] M1-T01 Spec 1.0 docs: `spec/form-definition/1.0/{README,CHANGELOG,meta-schema.json,roles.json}` (2020-12; all §7.3 types; `record` rejected; `x-*`; `source`; `i18n`; `role`) — §7.1–7.7 [meta-schema fixture tests]
- [x] M1-T02 `packages/form-schema-php` (MIT, path repo): readonly value objects, parser, canonical JSON + checksum — §7.1, §14.6 [unit, lvl 10 phpstan]
- [x] M1-T03 PHP meta-schema validation (opis) with error mapping — §7.5.1 [unit]
- [x] M1-T04 PHP logic: own JsonLogic dialect evaluator (ADR 0021) behind an interface, custom ops `dec.*`, `sum count min max date.diff date.add`, scope rules (`$root.`, built-ins) on brick/math — §7.4 [unit]
- [x] M1-T05 `packages/form-schema-js` (MIT, npm workspace, TS strict): types, ajv meta-schema validation, own logic evaluator + same ops on decimal.js — §7.1, §7.4 [vitest]
- [x] M1-T06 Linter PHP + JS: unique keys/scope, var refs, cycles, option uniqueness, numbering format, built-in arithmetic/comparison on number/money rejected, roles warn/error, `record` rejected, key-type-change vs published, deleted refs — §7.5.2, §7.6, §7.7 [unit + conformance]
- [x] M1-T07 Submission evaluator PHP + JS: visibility per row, required/requiredIf/readOnlyIf, defaults, calculated in dependency order with scale rounding, strip hidden/unknown, decimal-string enforcement, JSON Pointer errors with stable codes — §7.4, §7.5.3 [unit + conformance]
- [x] M1-T08 Conformance suite `spec/conformance/*.json` + PHP runner (Pest) + JS runner (vitest) + `npm run conformance` + CI job — §12 [both runners]
- [x] M1-T09 Data contract JSON Schema export (`data_json_schema`) — §7.1 [unit]
- [x] M1-T10 `forms` + `form_versions` (+`form_access`) migrations (jsonb, GIN), models, immutability (model guard + DB trigger allowing only `retired_at` NULL→value), policies — §6.4, §7.6 [feature tests]
- [x] M1-T11 Actions: SaveDraft (meta-schema+lint), Publish (version+1, checksum, changelog, audit), Rollback (new version copy), Retire — §7.6, §8.10 AC [feature tests]
- [x] M1-T12 Version diff (by element `id`, human terms) — §7.6 [unit]
- [x] M1-T13 Builder v1: Form resource (list: status, version, submission count; create blank; duplicate; archive), Builder field, one block per element type, nested repeater/section/columns, Hidden `id` + `_extra`, clone regenerates id + suffixes key, key lock after publish — §8.2 [Livewire tests]
- [x] M1-T14 Mapper builder ↔ spec, lossless incl. `x-*`/unknown via `_extra`; fixture + property round-trip tests — §8.2 AC [unit]
- [x] M1-T15 Logic editor v1: condition repeater (field/operator/value, AND/OR groups) compiling to JsonLogic, guided calculated expression editor, raw JSON view — §8.2 [unit + Livewire]
- [x] M1-T16 Draft autosave, lint panel blocking publish, publish modal (required changelog), version timeline, rollback, retire, diff UI — §8.2, §8.10 [Livewire tests]
- [x] M1-T17 JSON import/export (definition + design ref), templates in `spec/templates/` (service report, invoice, inspection checklist; rest in M3), create-from-template gallery setting `source` — §7.6, §8.12, D12 [feature tests]
- [x] M1-T18 Gate M1

## M2 Field App online (branch `m2-field-app`)
- [x] M2-T01 Devices: `devices` table, register on login (secret shown once, hash stored), `POST /api/v1/auth/device-status` (ok/reauth/wipe) by device secret, revoke + wipe, user deactivation revokes sessions/tokens/devices — §9.3, §6.4 [feature tests]
- [x] M2-T02 Submissions schema: `submissions` (client uuid PK, version pin, jsonb data, revision, number), `submission_events`, `attachments` (client uuid, private disk, sha256), `number_sequences` — §6.4, §9.2 [migration + model tests]
- [x] M2-T03 Submission state machine (in-house enum + action, ADR 0025) with events + audit per transition — §6.5 [unit + feature]
- [x] M2-T04 Access: FormAccessResolver (Owner/Admin all, else group/role grants), submission visibility per role, policies; `form_access_revoked` — §8.6, §9.3 [matrix tests]
- [x] M2-T05 API forms: `GET /forms`, `GET /forms/{form}/versions/{version}` (access-filtered) — §10 [feature + isolation cases]
- [x] M2-T06 API drafts: `PUT /submissions/{uuid}` (create/update, revision → 409), `GET /submissions`, `GET /submissions/{uuid}` — §9.2, §10 [feature]
- [x] M2-T07 Attachments: `POST /submissions/{uuid}/attachments` idempotent by client uuid, finfo sniffing, allow-list, size limits, image re-encode (EXIF stripped) — §11, §9.2 [feature]
- [x] M2-T08 Submit: `Idempotency-Key`, server re-evaluation (form-schema-php), attachment manifest, retired-version and revoked-access rejection, gap-free numbering (ON CONFLICT + FOR UPDATE) — §9.2, §8.10 AC [feature incl. concurrency]
- [x] M2-T09 `submission_values` projector (submit/resubmit/void) + `app:reproject {form?}` — §8.4, §6.4 [feature]
- [x] M2-T10 `GET /sync/manifest?since=` (forms, versions, revocations, tasks placeholder) — §9.2, §10 [feature]
- [x] M2-T11 OpenAPI 3.1 via dedoc/scramble at `/docs/api` (platform admins) — §10 [smoke test]
- [x] M2-T12 Field App shell `resources/field-app`: Vite (base `/app/`, entries app + preview), React 19, TanStack Router/Query, Tailwind 4, shadcn/ui, DE/EN i18n, login + 2FA + device registration, served by Laravel catch-all — §9.1, §6.1 [vitest + build]
- [x] M2-T13 Renderer (form-schema-js/react): all v1.0 types, live calculations via `evaluate()`, pointer errors, wizard/scroll, repeater cards/table + virtualization, signature_pad, photo capture/compression, location — §9.1, §9.2 [vitest + RTL]
- [x] M2-T14 Field App screens: home (drafts, start new), catalog, fill (server autosave), review & sign, submit, my submissions, settings — §9.1 [vitest + Playwright mobile]
- [x] M2-T15 Builder preview: `preview.html` entry in an iframe, origin-checked postMessage of the unsaved definition, phone/tablet toggle — §8.2, §6.1 [Playwright]
- [x] M2-T16 Fillable-PDF import: fixture generator (pdf-lib), qpdf JSON parser job, mapping table, repeater detection, `x-import`, import report, limits; ADR qpdf vs pdf.js — §8.13 [feature on fixtures]
- [x] M2-T17 E2E journeys 2 (template → edit → preview → publish) and 8 (PDF import); API isolation cases — §12 [Playwright]
- [x] M2-T18 Gate M2

## M3 Documents + email (branch `m3-documents`)
- [x] M3-T01 Branding: logo upload (private disk, sniffed, re-encoded), primary/accent colors, bundled OFL font choice, company details; applied to PDFs and mails — §8.5 [Livewire + unit]
- [x] M3-T02 Designs: `designs` (draft settings) + immutable `design_versions` (trigger), publish/rollback/diff like forms; Filament Design resource (paper, orientation, margins, header/footer with tokens, layout style, repeaters table/cards, photo grid, empty fields, signature placement, watermark) — §8.5, §8.10 [feature]
- [x] M3-T03 Renderer: canonical render input (definition, data, branding, design version, meta) → Blade + print CSS (paged media, repeated table headers, keepTogether, no truncation), inlined assets (photos downscaled ≤ 1600 px, logo, fonts), 25 MB budget, Gotenberg client via spatie/laravel-pdf Gotenberg driver — §8.5 [unit + integration vs Gotenberg]
- [x] M3-T04 Renditions: `renditions` (input_hash, file_sha256, renderer/form/design versions, page count), queued GenerateRendition on SubmissionSubmitted, re-render = new rendition, immutable; `GET /submissions/{uuid}/document` signed URL — §8.5, §8.10 AC, §10 [feature + Gotenberg integration: 60-line repeater, page count, text]
- [x] M3-T05 "Preview PDF" in the builder with sample data — §8.2 [Livewire]
- [x] M3-T06 Mail: per-org sender name/reply-to, optional SMTP override (encrypted) + test mail, queued branded Markdown mailables, `mail_messages` log with retry — §8.8 [feature with Mail fake + Mailpit integration]
- [x] M3-T07 Document delivery: attachment up to a size limit or expiring signed link (default 14 days, 410 when expired) — §8.8 [feature]
- [x] M3-T08 Send to customer: `POST /submissions/{uuid}/send` queued until number + PDF exist; admin action — §9.2, §10 [feature]
- [x] M3-T09 Admin submissions inbox: list per form/org with status filters, detail view (data, attachments, events, renditions, re-render) — §8.12 [Livewire]
- [x] M3-T10 Templates: delivery note, timesheet, handover protocol — §7.6 [template lint + publish tests]
- [x] M3-T11 CSV bulk member invitations — §8.12 [feature]
- [x] M3-T12 Gate M3 (E2E: submit in Field App → PDF in admin → email arrives in Mailpit)

## M4 Workflows + notifications → MVP 0.x (branch `m4-workflows`)
- [x] M4-T01 Workflow engine: `workflows` (trigger + config, conditions jsonb, steps jsonb, active), `workflow_runs` (definition snapshot, unique (workflow, submission, event id)), `workflow_step_runs` (status, attempts, log); event matching, JsonLogic conditions via form-schema-php, sequential queued steps with backoff, pause/resume, failure visible + admins notified + manual retry — §8.3 [feature: idempotency, duplicate events, retries]
- [x] M4-T02 Steps `send_email` (fixed / field value / submitter / group recipients, template with tokens, PDF attachment or link via SendDocuments), `notify` (users/groups/roles, channels), `set_status`, `generate_document` (chosen design), `webhook` (`webhook_endpoints`, encrypted secret, HMAC-SHA256 + timestamp headers, exponential retries, SSRF guard incl. redirects and IP pinning) — §8.3 [feature + AC: 127.0.0.1/10.0.0.0/8/169.254.169.254 refused]
- [x] M4-T03 Triggers: submission.submitted, status_changed (to), approved, rejected, form.published, schedule (cron), manual (button on a submission); "no review configured → completed" and "approved → completed after post-approval steps" (ADR) — §8.3, §6.5 [feature]
- [x] M4-T04 Review tasks: `tasks` (review, assignee user/group, due_at SLA, outcome, comment); `request_review` step creates the task and sets in_review; approve resumes the run, reject sets changes_requested with comment and notifies the submitter; resubmit starts a new event; overdue reminders — §8.3, §6.5 [feature]
- [x] M4-T05 Filament: Workflow resource (steps as Builder, conditions editor), Runs relation manager with per-step timeline, logs and retry; "My tasks" page; approve/reject with comment and manual workflows in the submissions inbox — §8.3, §8.12 [Livewire]
- [x] M4-T06 Notifications: real Notifier (database + branded mail via the mail module, queued), per-user preferences per event and channel, events review assigned / changes requested / approved / workflow failed / new form available, admin bell (Filament database notifications, polling), API `GET /notifications`, `POST /notifications/read`, preferences API — §8.9, §10 [feature: recipients + preferences AC]
- [x] M4-T07 API: `GET /tasks`, `POST /tasks/{id}/complete` (approve/reject + comment, idempotent), submission `review` + `open_task_id`, tasks in the sync manifest; contract + isolation cases — §10, §9.2 [feature]
- [x] M4-T08 Field App: open tasks on home, approve/reject on the submission detail, changes-requested banner with the reviewer comment + edit + resubmit, notification centre (badge, list, mark read), notification preferences in settings — §9.1, §8.9 [vitest]
- [x] M4-T09 Demo seed: reviewer group, "Prüfen und an Kunden senden" workflow (request_review → send_email with PDF to the customer field), a webhook endpoint example (disabled) — [seeder test]
- [x] M4-T10 E2E: journey 4 (reject → resubmit → approve → PDF in Mailpit with correct totals), journey 5 (v2 rename + new field, re-render keeps input_hash/text/pages, rollback creates v3), journey 1 (owner registers an organization and invites a designer and a field worker; dev stack enables registration) — §12, §8.3 AC
- [x] M4-T11 MVP release 0.1.0: CHANGELOG.md, README quick start + feature overview, release notes, tag `v0.1.0` — §13
- [x] M4-T12 Gate M4

## M5 Reports + dashboards (branch `m5-reports`)
- [x] M5-T01 Submission explorer per form: Filament page with dynamic columns from schema keys (union across versions by key, labels from the newest version), filters (date, status, submitter, group, field values for select/boolean/date/number), search, bulk actions; queries `submission_values`; visibility per §8.6 — §8.4 [Livewire + AC: three versions]
- [x] M5-T02 Exports: custom queued export job (openspout, reusing Filament's `Export` model + completion notifications) as XLSX (repeaters on a second sheet linked by number) and CSV, bulk PDF ZIP of renditions; `export_ready` notification event; decimal-exact money — §8.4 [feature + AC: 10k in background, notifies]
- [x] M5-T03 Saved reports: form + filters + group-by + metric (count, sum, avg) as table and chart, decimal-exact sums in SQL; share within the organization by permission; scheduled report emails (weekly/monthly, CSV attachment) — §8.4 [feature]
- [x] M5-T04 Dashboards: role-aware defaults (Owner KPIs: submissions this week, pending reviews, stale drafts, documents sent; submissions over time per form; workflow failures — Reviewer: review queue, recent submissions — Designer: draft forms, recently published), date-range filter, per-org cache with short TTL; pin saved reports as widgets — §8.1 [Livewire + AC: tenant scoping]
- [x] M5-T05 Performance: load generator (`app:seed-load {org} {count}`), `submission_values` and widget queries index-backed at 100k submissions (EXPLAIN-based tests), export of 10k measured — §8.1 AC, §8.4 AC [feature + measurement]
- [x] M5-T06 Permissions: "Reports and exports" column (§8.6) + "View submissions" row for exported rows; audit exports — §8.6, §8.11 [matrix tests]
- [x] M5-T07 E2E: explorer columns across versions, export downloads with notification, saved report pinned on the dashboard — §12
- [x] M5-T08 Gate M5

## M6 Offline-first (branch `m6-offline`)
- [x] M6-T01 Local store: Dexie 4.4 schema (drafts with base server copy + revision, outbox, attachment blobs, cached forms/versions/designs/options, sync meta), migration of the M2 kv store (device credentials, autosave copies), versioned upgrades; ADR 0035 offline architecture + versions (Dexie 4.4.6, vite-plugin-pwa 2.0.0, Workbox 7.4.1, fake-indexeddb for tests) — §9.2, §14.2 [unit, fake-indexeddb]
- [x] M6-T02 Local-first autosave: every change written to IndexedDB (debounced, flushed on pagehide/visibilitychange), drafts list and "Continue drafts" from the local store merged with the server; new drafts started offline get UUIDv7 ids — §9.2 [unit + AC: killed tab loses no data]
- [x] M6-T03 Sync engine + outbox: ordered per submission (attachments → draft PUT with revision → submit with persisted Idempotency-Key → queued send), runs on app start, `online`, `visibilitychange`, "Sync now"; Background Sync tag as a Chromium-only wake-up; backoff, single-flight across tabs (Web Locks / BroadcastChannel); 401/419 → device-status (`reauth` keeps outbox, `wipe` clears) — §9.2, §9.3 [unit + AC: syncs exactly once]
- [x] M6-T04 Attachment queue: photos (long edge ≤ 2048 px, EXIF stripped, GPS only on opt-in) and signatures stored as blobs, uploaded with client ids and the attachment manifest, resumable after reload, storage usage — §9.2 [unit]
- [x] M6-T05 Conflicts: a 409 on a queued draft PUT pauses that submission's outbox, shows both versions (local vs server, field-level diff) and applies the user's choice; queued submit waits for the resolution — §9.2 [unit + component]
- [x] M6-T06 Offline catalog + app shell: vite-plugin-pwa (Workbox precache of the /app shell, SW scope `/app`, `preview.html` outside the scope); cache every assigned form's published versions, designs and option lists; refresh through `/sync/manifest?since=`, fetch every `fillable_form_ids` entry not cached (ADR 0027), drop revoked forms — §5, §9.2 [unit + API feature]
- [x] M6-T07 Offline UX: online/offline and pending-sync indicators, "number pending", queued send notice, Settings (storage usage, last sync, "Sync now"), `navigator.storage.persist()`, iOS install prompt + unsynced-data warning when not installed, Android install button — §9.1, §9.2 [component]
- [x] M6-T08 Logout + wipe: logout warns about unsynced items, then clears Dexie, Cache Storage and the SW; `wipe` from device-status clears everything; deactivated user's next sync leaves no local data — §9.3 [unit + AC]
- [x] M6-T09 Totals parity: calculated totals on the device equal the server's for the 30-line service report (JS evaluation vs stored server values) — §9.2 AC [feature + unit]
- [x] M6-T10 E2E: journey 3 (offline fill with 30 lines, photo, signature → reconnect → syncs once, number assigned), journey 7 (session expires offline → re-login → outbox syncs, no loss), killed tab, deactivated user wipe — §12
- [x] M6-T11 Gate M6

## M7 SSO + Builder v2 — break down at M6 gate
- OIDC per org (Socialite), domain verification (DNS TXT, fake resolver), home-realm discovery, JIT + claim mapping, enforce SSO + break-glass; Keycloak in CI; SAML (socialiteproviders/saml2); Builder v2 page (palette/canvas/inspector, SortableJS, keyboard moves, undo/redo).

## M8 Hardening → 1.0 — break down at M7 gate
- Audit log UI + CSV + retention pruning (+ optional hash chain); GDPR (per-form retention, legal hold, user export/erasure); accessibility audit; load tests (100k submissions); security review; CSP/HSTS; builder preview on an opaque origin (QUESTIONS #10); docs site, install + upgrade + backup guides; web push (if feasible); `/platform` operator panel; FINAL_REPORT.
