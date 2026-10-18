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

## M7 SSO + Builder v2 (branch `m7-sso-builder`)
- [x] M7-T01 SSO architecture ADR + versions (laravel/socialite 5.31, socialiteproviders/manager 4.10 + keycloak/microsoft-azure, socialiteproviders/saml2 4.10, filament-socialite 3.3 only if it fits per-org dynamic providers); `identity_providers` (org, type oidc/saml, encrypted config, status) + `sso_domains` (org, domain, TXT token, verified_at) — §8.7, §6.4, §14.2 [model + encryption tests]
- [x] M7-T02 Domain verification: DNS TXT check behind a resolver interface (fake resolver in tests/CI), periodic re-check, uniqueness across orgs, audit — §8.7 [feature]
- [x] M7-T03 OIDC login per organization: dynamic Socialite provider (Entra, Google, generic OIDC/Keycloak) with PKCE/state/nonce, issuer + audience checks, callback → session; admin (Filament) and Field App (`/auth/sso/...` + device registration) — §8.7, §9.3 [feature with fake IdP]
- [x] M7-T04 Home-realm discovery: email-first login on admin and Field App; verified SSO domain → redirect to IdP; otherwise password — §8.7 [feature + Livewire + Field App test]
- [x] M7-T05 JIT provisioning + claim mapping: create user + membership with default role/group; optional claims → roles/groups (e.g. Entra groups); updates on each login; deactivated members refused — §8.7, §8.6 [feature]
- [x] M7-T06 Enforce SSO per org: password login (admin + Field App + API) rejected for its verified domains; break-glass Owner keeps password + MFA; password reset blocked for enforced domains — §8.7 AC [feature]
- [x] M7-T07 SSO admin UI (Filament): provider config (secret shown once), domains with TXT instructions + verify, test login, enforcement toggle with break-glass check; `sso_misconfigured` notification; audit of every auth event — §8.7, §8.9, §8.11 [Livewire]
- [x] M7-T08 Keycloak in Docker (dev + CI service) with a realm import (client, users, groups claim); E2E: SSO login creates the user with the mapped role; enforcement rejects password login — §8.7 AC, §12 [E2E]
- [x] M7-T09 SAML 2.0 via socialiteproviders/saml2: per-org metadata/ACS endpoints, signed assertions, same JIT/mapping/enforcement; Keycloak SAML client in the realm — §8.7 [feature + E2E if feasible]
- [x] M7-T10 Builder v2 architecture ADR: custom Filament page (palette/canvas/inspector), Livewire + Alpine + SortableJS (version check), canonical JSON as the only state, reuse of v1 inspector forms/logic editor, autosave + lint + preview integration — §8.2 [n/a]
- [x] M7-T11 Builder v2 canvas: drag from palette, reorder within/between pages and containers (sections, columns, repeaters), keyboard alternatives (move up/down/into/out), selection + inspector, undo/redo; same mapper/linter/publish as v1, switch v1 ↔ v2 without migration — §8.2 [Livewire + JS unit + a11y]
- [x] M7-T12 Builder v2 E2E: build the §7.2 example by drag and keyboard, publish, preview matches; v1 ↔ v2 round trip — §8.2 AC, §12 [E2E]
- [x] M7-T13 Gate M7

## M8 Hardening → 1.0 (branch `m8-hardening`)
- [x] M8-T01 Audit log UI: read-only Filament resource (filters by actor/action/subject/date, CSV export), "History" on key records, retention pruning job (365 days default, configurable), UPDATE/DELETE blocked by a DB trigger, optional hash chain (tamper evidence) + verify command; audit coverage test: every §8.11 action produces exactly one entry, secrets redacted — §8.11 AC [feature + Livewire]
- [x] M8-T02 GDPR: per-form retention (delete or anonymize after N months) with legal hold, scheduled job, re-projection of submission_values on anonymization; user data export (ZIP/JSON) and erasure (anonymize user, keep legally required records); admin actions + audit; docs — §11 Privacy [feature]
- [x] M8-T03 Security hardening: security headers (CSP, HSTS, Referrer-Policy, Permissions-Policy) on admin/app/api, secure cookies, builder preview on an opaque origin (sandbox without allow-same-origin, QUESTIONS #10), upload validation review (+ optional ClamAV hook), structured JSON logs, optional Sentry-compatible reporting; OWASP ASVS L2 review by subagents with fixes — §11 Security/Operability [feature + review]
- [x] M8-T04 Accessibility audit: axe on every Field App screen, key admin pages and Builder v2 (Playwright + @axe-core/playwright), keyboard walkthroughs, fixes; document screen-reader checks that need humans — §11 Accessibility [E2E + fixes]
- [x] M8-T05 Performance and load: 100k submissions (app:seed-load): admin pages p95 < 500 ms, API p95 < 300 ms, 10-page PDF < 10 s, 300-element form and 500-row repeater responsive, Field App first load budget (bundle size, Lighthouse-like metrics) — evidence + regressions fixed — §11 Performance [measurement]
- [x] M8-T06 Operator panel `/platform` (optional per §8.12): organizations, health (queues, failed jobs, Gotenberg, mail), audited impersonation with banner and exit — §8.12 [Livewire + policy]
- [x] M8-T07 Documentation: install guide (Docker, first org, mail, SSO), upgrade guide (0.x → 1.0, migrations, Horizon), backup/restore guide + restore drill script, admin guide (forms, workflows, reports, SSO), Field App user guide, API reference link (OpenAPI), docs index (static docs in `docs/guide/`, ADR on the docs site choice) — §11 Operability, §13 M8 [docs review]
- [x] M8-T08 Carry-overs: reviewer detail error panel after approving (M7 E2E leftover page), builder v1 vs v2 default decision (QUESTIONS #8), web push only if feasible without new infra (else ADR "deferred"), open QUESTIONS that are tasks — [fixes + ADRs]
- [x] M8-T11 Integration API tokens (§10 gap found by the docs review): Sanctum personal access tokens with abilities (scopes per resource), Filament UI to create (shown once), list, revoke; ability checks on /api/v1 routes; rate limit per token; audit; docs (api.md) — §10 [feature]
- [x] M8-T12 Production env template `.env.production.example` (COMPOSE_FILE=compose.yaml, APP_ENV=production, JSON logs, secure cookies, TRUSTED_PROXIES), README quick start split dev/prod, remove no-op AWS_* vars — §11 Operability [docs + config]
- [x] M8-T09 Release 1.0: CHANGELOG, release notes, versions, final full gate on `main`, FINAL_REPORT.md, STATE "Run complete" — §16.10
- [x] M8-T10 Gate M8

## v2: form-schema-app 2.0 (BUILD_PROMPT_V2.md; plan `docs/plans/2026-10-17-001-feat-docker-distribution-v2-plan.md`)
Task text names the plan unit (U-ID), R-IDs and journeys. "Plan:" refers to the plan's KTDs and unit test
scenarios, which are the acceptance detail. The plan's phase order is binding where it differs from
BUILD_PROMPT_V2 §12 (E16 measurement U15 in M11, reset U23 in M12).

## M9 Release foundation → 2.0.0-alpha.1 (branch `m9-release`)
- [x] M9-T01 Bootstrap: BUILD_PROMPT_V2 and the plan committed; AGENTS.md points at both; ADRs for E1–E18 with the §5/Appendix A versions; M9–M13 in PLAN.md; STATE.md — §15.9 [n/a]
- [x] M9-T02 U1 Image: ops role (entrypoint skips optimize/migrations for `fsa` and the backup daemon), allow-list `.dockerignore`, writable XDG dirs (R6 log fix), `APP_VERSION`/`APP_REVISION` → OCI labels + `config('app.version')`, pinned per-arch binaries (Docker CLI + Compose, PGDG `postgresql-client-16`, age + batchpass, cosign; cosign later dropped and Compose and age built from source, ADR 0071), size ≤ 1.0 GB — R1, R5, R6, E17, KTD8, KTD9 [unit + image contract journey, both archs]
- [x] M9-T03 U2 Instance domain core: `DockerGateway` + CLI implementation, versioned `InstanceState`, `InstanceDirectory`, per-service `EnvFiles`, `InstanceLock` (container ID + start time), `OperationJournal`, `Supervisor`, `CommandResult` envelope, `ExitCode` (0–10; 1 = internal error, ADR 0066), Runtime boundary arch test, coverage 90 % for `app/Domain/Instance/` — R49, R50, R54, R55, R63, R69, KTD1–KTD7, KTD24, KTD25 [unit with RecordingDockerGateway; lock + journal first]
- [x] M9-T04 U3 Compose renderer, env files, resource budget: local mode, capture mail to Mailpit, UUID labels, networks, limits within E16, runtime dir mount, no socket, no `RUN_MIGRATIONS`, strict renderer-input grammar — R9, R22, R63, E16, KTD6, KTD7, KTD11, KTD13 [unit + `docker compose config` in J1]
- [x] M9-T05 U6 Sign-in links, non-interactive `app:install` (`--password-file`, passwordless owner mode, JSON), `fsa login-link` (hashed, ≤15 min, fragment + POST), operator grant, Horizon behind operator + `/platform` session, audit actor "cli" — R12, R26–R28, R50, KTD21, KTD25 [feature + E2E sign-in]
- [x] M9-T06 U7 Release pipeline, manifest, harness: `release.yml` (buildx SBOM/provenance, cosign keyless, Trivy, anonymous pull; actionlint), `release:manifest` / `release:version` (ADR 0067) + schema, harness compose (`registry:3`, static release server, local signing key), Install test suite, CI linters, first `RELEASING.md`. Verified by building, signing and serving the harness release (the install itself is U5's J1) — R1–R4, R8, R52, E14, E18, KTD22, KTD23 [unit + harness journey]
- [x] M9-T07 U4 `install.sh`, shim, host facts: function-wrapped POSIX script, manifest verification with the pinned cosign image and built-in identity (anchored regexp), harness-only `FSA_TRUST_KEY`, file-flag parent mounts, nonce check, browser hand-over (`FSA_OPEN_URL_FILE` instead of `open_url`, ADR 0070) — R7, R8, R58, KTD2, KTD22 [shellcheck + bootstrap journey]
- [x] M9-T08 U5 Lifecycle commands, local mode: install (journaled, resumable, idempotent), doctor (incl. permissions, port probe), status (read-only), logs, version, uninstall (`--keep-backups`, `--orphans`) — R9–R15, R44, R45, R54, R63, R69, AE3–AE5, AE9 [feature + J1, J12]
- [x] M9-T09 Gate M9: §15.6 (all gates, J1 + J12 on arm64 and amd64 under emulation, 75 E2E, demo transcripts, reviewer subagents, M9.md), build `2.0.0-alpha.1` locally, merge, tag `m9-done`, break down M10

## M10 Modes → 2.0.0-alpha.2 (branch `m10-modes`)
Order: T01 first (the app must resolve its URL, hosts and scheme from runtime state before edge and proxy
modes can be tested); T02 and T03 in parallel worktrees (both extend the renderer); T04 last.
- [x] M10-T01 U10 Runtime state in the app: `App\Domain\Instance\Runtime` (RuntimeState, RuntimeUrl, OperationGate, TunnelPathPolicy; no ops imports, arch test), `ResolveInstanceRuntime` global middleware (expired tunnel host → 503 + Retry-After; gate → 503 except the per-operation smoke token from the internal network; tunnel peer → path policy, host only from runtime), `TrustedHosts::patterns()` + runtime hosts, URL root from the request origin for allow-listed hosts else the instance URL, `SecureTransport`/cookies per resolved scheme, `__Host-` session cookie in HTTPS modes, `JobProcessing` listener for queued jobs and notifications, mtime+inode+size cache, last-good fallback, fail-closed gate — R21, R56, R62 (app side), R65 (app side), KTD11, KTD12, KTD24 [feature tests incl. forged X-Forwarded-Host from the tunnel peer; no-op without the runtime dir]
- [ ] M10-T02 U8 Edge service and domain mode: CaddyfileRenderer (structured, quoted), edge on the official Caddy image (non-root, NET_BIND_SERVICE, fixed address on an edge network = the only trusted proxy), ACME via `acme_ca`/`acme_ca_root` (Pebble + challtestsrv in the harness, `--acme-staging`), HTTPS redirect, 1.0 headers pass through, certificate volume kept across config changes, `/_mail/` behind `forward_auth` to `CatcherAuthController` (operator `/platform` session with MFA, audit `catcher_opened`, XFO/nosniff/CSP; fallback loopback-only if Mailpit's UI does not work behind it — verify first), `fsa install --domain` enabled (DNS preflight already in doctor) — R16, R66, F1, J2, KTD10 [unit + feature + J2 on arm64 and amd64 emulated]
- [x] M10-T03 U9 Proxy mode and stack file: `--behind-proxy` with Traefik labels, caddy-docker-proxy labels or a loopback port; only the app joins the shared external network; trust exactly the proxy's address (forged XFF from a neighbour container rejected); trusted hosts from `--url`; `distribution/stack/compose.stack.yaml` generated from the same renderer (env-only, limits documented, drift test) — R17, R18, J3 [unit + J3 with Traefik]
- [ ] M10-T04 U11 `fsa config show|set` with apply, verify and revert: appliers for domain, port, URL and trusted proxies (mail comes in M11); validate → render state/env/runtime → apply → verify (health, certificate) → revert with `reverted: true` on failure; `config set domain` needs `--yes` and lists SSO organizations and signed links that change; `config show` redacts secrets and names outbound contacts; idempotent `changed:false`; audit `instance.config_changed` through a one-off — R31, R32, R54, KTD6, KTD26 [feature + journey local→domain switch and failed-ACME revert]
- [ ] M10-T05 Gate M10: §15.6 (all gates, J2 + J3 (+ J1/J12 regression) on arm64 and amd64 emulated, E2E, reviewer subagents, M10.md), build `2.0.0-alpha.2` locally, merge, tag `m10-done`, break down M11

## M11 Mail, data and phones → 2.0.0-beta.1 (branch `m11-mail-phones`)
- [ ] M11-T01 U12 Capture-mode experience (banner variants, catcher, switch to SMTP) — J4
- [ ] M11-T02 U13 Demo data and password rotation — J5 (demo part)
- [ ] M11-T03 U14 Phone HTTPS: local CA with trust page, tunnel with expiry — J6
- [ ] M11-T04 U15 E16 resource budget measurement — J10
- [ ] M11-T05 Gate M11, build `2.0.0-beta.1`, break down M12

## M12 Lifecycle → 2.0.0-rc.1 (branch `m12-lifecycle`)
- [ ] M12-T01 U16 Backups (snapshot, HMAC, encryption, retention, backup service, `backup_runs`)
- [ ] M12-T02 U17 Restore, drill, `--from-backup` — J8
- [ ] M12-T03 U18 Upgrade supervisor and rollback — J7
- [ ] M12-T04 U19 Adopt a 1.0 install — J9
- [ ] M12-T05 U23 Reset — J5 (reset part)
- [ ] M12-T06 Gate M12, build `2.0.0-rc.1`, break down M13

## M13 Operability and release → 2.0.0 (branch `m13-release`)
- [ ] M13-T01 U20 `/platform` instance page and update check
- [ ] M13-T02 U21 Agent guide, docs rewrite, `RELEASING.md` completed — J11
- [ ] M13-T03 U22 Dogfooding: E2E suite against an `fsa` instance, all journeys in CI
- [ ] M13-T04 Gate M13, release 2.0.0 per §15.10 (local tag `v2.0.0`, FINAL_REPORT_V2.md, STATE "Run complete")
