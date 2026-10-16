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
- [ ] M2-T11 OpenAPI 3.1 via dedoc/scramble at `/docs/api` (platform admins) — §10 [smoke test]
- [ ] M2-T12 Field App shell `resources/field-app`: Vite (base `/app/`, entries app + preview), React 19, TanStack Router/Query, Tailwind 4, shadcn/ui, DE/EN i18n, login + 2FA + device registration, served by Laravel catch-all — §9.1, §6.1 [vitest + build]
- [ ] M2-T13 Renderer (form-schema-js/react): all v1.0 types, live calculations via `evaluate()`, pointer errors, wizard/scroll, repeater cards/table + virtualization, signature_pad, photo capture/compression, location — §9.1, §9.2 [vitest + RTL]
- [ ] M2-T14 Field App screens: home (drafts, start new), catalog, fill (server autosave), review & sign, submit, my submissions, settings — §9.1 [vitest + Playwright mobile]
- [ ] M2-T15 Builder preview: `preview.html` entry in an iframe, origin-checked postMessage of the unsaved definition, phone/tablet toggle — §8.2, §6.1 [Playwright]
- [ ] M2-T16 Fillable-PDF import: fixture generator (pdf-lib), qpdf JSON parser job, mapping table, repeater detection, `x-import`, import report, limits; ADR qpdf vs pdf.js — §8.13 [feature on fixtures]
- [ ] M2-T17 E2E journeys 2 (template → edit → preview → publish) and 8 (PDF import); API isolation cases — §12 [Playwright]
- [ ] M2-T18 Gate M2

## M3 Documents + email — break down at M2 gate
- Branding (logo, colors, OFL fonts, company details); designs + design versions + rollback; Blade print templates; Gotenberg rendering (inlined assets, size budget); renditions (input_hash, file_sha256); Preview PDF; mail settings (per-org SMTP encrypted, test mail), `mail_messages` log + retry; signed expiring links (410); send to customer; remaining templates (delivery note, timesheet, handover protocol).

## M4 Workflows + notifications — break down at M3 gate
- Workflow model + Builder steps; triggers; conditions; runs with snapshot + idempotency; steps send_email/notify/request_review/set_status/generate_document/webhook (HMAC, backoff, SSRF guard); tasks + review UI + API; run timeline + retry; notifications (db + mail, preferences, digest); field app tasks + notification center; E2E journey 4; MVP 0.x release notes.

## M5 Reports + dashboards — break down at M4 gate
- Submission explorer (dynamic columns across versions, filters, search, bulk); custom export job (openspout, repeater sheet, Export model notifications); bulk PDF ZIP; saved reports + charts + pin; role-aware dashboards with cached widgets; scheduled report emails; 100k-row index check.

## M6 Offline-first — break down at M5 gate
- Dexie store; outbox + sync engine (start/online/visibilitychange/manual, Background Sync enhancement); attachment queue + compression + EXIF strip; conflict UI on 409; storage persistence + iOS install prompt; logout wipe; device wipe; E2E journeys 3, 7.

## M7 SSO + Builder v2 — break down at M6 gate
- OIDC per org (Socialite), domain verification (DNS TXT, fake resolver), home-realm discovery, JIT + claim mapping, enforce SSO + break-glass; Keycloak in CI; SAML (socialiteproviders/saml2); Builder v2 page (palette/canvas/inspector, SortableJS, keyboard moves, undo/redo).

## M8 Hardening → 1.0 — break down at M7 gate
- Audit log UI + CSV + retention pruning (+ optional hash chain); GDPR (per-form retention, legal hold, user export/erasure); accessibility audit; load tests (100k submissions); security review; CSP/HSTS; docs site, install + upgrade + backup guides; web push (if feasible); `/platform` operator panel; FINAL_REPORT.
