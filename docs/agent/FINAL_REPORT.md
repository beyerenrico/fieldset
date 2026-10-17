# Final report: form-schema-app 1.0.0

Autonomous build per `docs/agent/BUILD_PROMPT.md`, milestones M0–M8, released as **1.0.0** (tag `v1.0.0`).
Every task in `docs/agent/PLAN.md` is ticked. Gate reports per milestone are in `docs/agent/milestones/M0.md` …
`M8.md`; the decisions are in `docs/adr/` (0001–0046); items for human review are in `docs/agent/QUESTIONS.md`.

## What was built
A free, self-hostable forms platform for small and medium-sized businesses (AGPL-3.0 app; MIT spec and SDKs).

| Milestone | Delivered | Release |
|---|---|---|
| M0 Foundation | Laravel 13 + Filament 5, multi-tenant organizations with a throwing tenant scope, roles as capabilities, groups, invitations, MFA, audit baseline, Docker, CI | |
| M1 Schema core + Builder v1 | Form Definition Spec 1.0 (JSON Schema, README, conformance suite of 391 cases), PHP and JS SDKs with their own JsonLogic dialect, forms with immutable versions, publish/rollback/retire/diff, templates, classic builder | |
| M2 Field App online | Submission API (client UUIDs, idempotency, gap-free numbers, events, attachments), React renderer, the Field App PWA, fillable-PDF import, OpenAPI docs | |
| M3 Documents + email | Branded, versioned designs; immutable PDFs via Gotenberg; mail log with retries; "send to customer"; admin inbox | |
| M4 Workflows + notifications | Workflow engine (triggers, conditions, reviews with SLA, emails, signed webhooks), in-app and email notifications | 0.1.0 |
| M5 Reports + dashboards | Submission explorer across versions, XLSX/CSV/PDF-ZIP exports, saved and scheduled reports, role-aware dashboards | |
| M6 Offline-first | Dexie store, outbox with exactly-once sync, service worker, offline attachments, device management | 0.2.0 |
| M7 SSO + Builder v2 | OIDC and SAML per organization with domain verification, discovery, JIT and enforcement; the keyboard-operable canvas builder | |
| M8 Hardening | Append-only audit log with hash chain; GDPR retention, legal holds, export and erasure; CSP and security headers, session timeouts, upload scanning, JSON logs; accessibility audit; performance at 100k submissions; `/platform` operator panel; integration API tokens; guides | **1.0.0** |

Details: `README.md`, `CHANGELOG.md`, `docs/releases/1.0.0.md`.

## Install and run
- **Production:** `docs/guide/install.md`, starting from `.env.production.example`. It covers the reverse proxy,
  `TRUSTED_PROXIES`, mail, SSO, backups (`scripts/backup.sh`, `scripts/restore-drill.sh`) and the audit
  tamper-evidence setup. Upgrades: `docs/guide/upgrade.md`.
- **Local trial:**
  ```bash
  cp .env.example .env
  sed -i.bak "s|^APP_KEY=.*|APP_KEY=base64:$(openssl rand -base64 32)|" .env
  docker compose up -d --build --wait            # http://localhost:8080
  docker compose exec app php artisan app:install
  ```
- **Development:** README "Quick start" (PHP 8.4 on the host, services in Docker, demo seed with password
  `password`). Admin at `/admin`, Field App at `/app`, API docs at `/docs/api`, operator panel at `/platform`.

## Tests and coverage
Final full gate on `main` (merge commit 28fad62, same tree as the M8 gate):
- **PHP (Pest 5):** 4652 tests, 18,982 assertions, all green (`scripts/coverage.sh`).
  - Coverage: 95.11 % in total; the PHP SDK is at 99.69 %, and `Domain/Forms/Schema` and tenancy are at 100 %.
  - The minimums are 80 % in total and 95 % on those paths.
- **Static analysis:**
  - PHPStan: level 8 for the app, level 10 for the SDK and schema code; 0 errors.
  - Rector: 0 files to change. Pint: clean.
- **JavaScript (Vitest):** 1,591 tests across four workspaces, all green: form-schema-js 611, renderer 111,
  Field App 623, canvas builder 246.
  - Typecheck, ESLint, Prettier and the production build: clean.
- **Conformance:** 391 PHP and 392 JS cases.
- **Supply chain:**
  - License check: 207 Composer and 660 npm packages.
  - `npm audit`: 0 vulnerabilities.
  - `composer audit` (Composer 2.10.3, CI image): no advisories. One abandoned, dev-only package is ignored by name.
- **E2E (Playwright):** 75 of 75 journeys green in two runs, each on a freshly seeded Docker stack with Keycloak.
  - Every journey runs a CSP-violation guard.
  - The runs include 36 axe WCAG 2.2 AA scans and keyboard walkthroughs.

History of the gate (PHP tests / coverage / E2E journeys): M0 190 / 90.08 % / 3 · M1 2837 / 95.62 % / 4 ·
M2 3016 / 94.77 % / 9 · M3 3346 / 95.09 % / 12 · M4 3594 / 94.84 % / 18 · M5 3766 / 94.98 % / 20 ·
M6 3799 / 95.00 % / 24 · M7 4303 / 95.10 % / 34 · M8 4652 / 95.11 % / 75.

Performance at 100k submissions (`docs/agent/milestones/evidence/M8/performance.md`):
- Admin pages: 26 of 27 page types are under 500 ms p95.
- API: every series is under 300 ms, the slowest at 125 ms.
- A 10-page PDF takes 2.3 s from dispatch to the stored file.
- The Field App's first load takes 2.07 s on slow 4G.
- The exceptions are listed under "Deviations".

## Deviations from the specification
| Deviation | ADR |
|---|---|
| Placeholders resolved as `form-schema-app` / `localhost` | 0001 |
| One TOTP secret and recovery-code set for Filament and Fortify; recovery codes stored encrypted, not hashed | 0016 |
| Capability permissions and an own role resource instead of Filament Shield | 0017 |
| One 30-day rolling session for the offline Field App; the admin panel adds 30 min idle / 12 h absolute timeouts | 0018, 0040 |
| Own JsonLogic dialect evaluator in both SDKs instead of json-logic-php/js; a restricted regex subset | 0021 |
| Builder blocks keep a `_source` snapshot, not only `_extra` | 0022 |
| PDF import reads AcroForms with the qpdf CLI, not pdf.js | 0024 |
| Own status machine and `attachments` table instead of spatie model-states and medialibrary | 0025, 0026 |
| Generic invoices only, no XRechnung/ZUGFeRD (decision D6) | 0008 |
| The canvas builder replaces the classic builder as the default; form access, versions and the import report remain in the classic builder | 0044 |
| Web push (§8.9 "comes in v1.0") deferred; in-app and email notifications only | 0045 |
| Performance exceptions: classic builder page 1.05 s; Field App first load 3.5 s under Lighthouse devtools throttling (2.07 s on slow 4G); adding the 501st repeater row 203 ms at 4× CPU | 0046 |
| Audit hash chain without a secret key: tamper evidence against the database owner needs chain heads shipped outside the database | 0038 |
| Documentation as plain Markdown in `docs/guide/` instead of a docs site | 0042 |
| Not built: the optional daily digest (§8.9), user-arranged dashboard widgets (§8.1 "later") | M4.md, M5.md |

## Open questions and blocked items
- **Blocked tasks:** none.
- **Items for human review:** `docs/agent/QUESTIONS.md`. M8 resolved #5, #8 and #10. The most consequential
  open ones:
  - #3: no e-invoice output.
  - #9 and #15: devices are per user.
  - #13: Background Sync only wakes open tabs; iOS syncs when the app is opened.
  - #18: SSO from the installed iOS PWA is untested.
  - #19: web push is deferred.
  - #20: there is no typed signature.
  - #21: the performance exceptions above.
- **Needs humans:**
  - Screen readers, zoom and real devices (`docs/agent/milestones/evidence/M8/a11y.md`).
  - A tax advisor for invoices.
  - A legal review of retention defaults and of anonymization versus legal record-keeping.

## Recommended next steps
1. Test on real devices: the iOS PWA with SSO, Android mid-range first load, screen readers (QUESTIONS #18, a11y.md).
2. Retire the classic builder: move form access, versions and the import report to their own pages (ADR 0044, 0046).
3. Field App first load: precompressed brotli and a smaller preloaded chunk; defer the autosave write when a
   repeater row is added (performance.md "Remaining risks").
4. Before forms pass ~150k submissions, cache saved report results or add a covering index. Add audit log
   indexes if the table reaches millions of rows.
5. Production hardening: run the application as a non-superuser role with the audit maintenance role, and
   ship `audit:verify --heads` output off the host (install guide).
6. Decide web push for 1.1 (ADR 0045) and e-invoicing (Appendix B4, needs a tax advisor).
7. `/horizon` and the API docs: gate them by the operator role instead of `PLATFORM_ADMIN_EMAILS`.
