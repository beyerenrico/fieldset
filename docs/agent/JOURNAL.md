# JOURNAL (append-only, ≤10 lines per entry)

## 2026-10-16 bootstrap
- Verified stack versions on Packagist/npm (all match §5; TS latest is 7.0.2, pin decided at install).
- Wrote AGENTS.md, CLAUDE.md, ADRs 0001–0015 (placeholders, stack, D1–D13), PLAN (M0/M1 detailed),
  STATE, QUESTIONS, LEARNINGS. Commit: bootstrap.

## 2026-10-16 M0 core (T01, T04–T08, T10–T12)
- Laravel 13.37 + Pest 5 + Larastan + Pint + Rector; Postgres/Redis/Gotenberg/Mailpit in Docker.
- Tenancy: Context-hidden org id, throwing scope, worker test proves restore-before-unserialize.
- Filament 5 panel w/ Organization tenant, registration/profile; Fortify headless under /auth;
  shared TOTP + recovery codes (ADR 0016); API envelope, X-Organization-Id, /me.
- Roles: capability catalog + per-org seeded roles, literal §8.6 matrix test (ADR 0017, Shield removed).
- Audit: EnrichActivity (org/actor/ip/ua/request id), Redactor, append-only model, auth listener.
- 149 tests green, PHPStan lvl 8 clean.

## 2026-10-16 M0 gate
- Members/Groups/Roles resources, invitations, app:install, demo seed, CI, licenses, Playwright E2E.
- Reviewer subagents (security, correctness): 3 high + test-proof gap fixed (93d7328): role escalation
  guard, single-use invitations, email-change verification, real Context-before-unserialize proof.
- Gate: 190 tests, PHPStan 0, coverage 90.08 % (tenancy 100 %), E2E 3/3. Report: milestones/M0.md.
- Drafted Spec 1.0 + conformance suite; ADR 0021 own JsonLogic evaluator.

## 2026-10-16 M1 implementation
- Spec 1.0 + 250 conformance cases; parallel subagents built form-schema-js (389b967) and
  form-schema-php (cd6b21c); both pass all cases, parity fuzzed (~40k inputs).
- Forms domain: forms/form_versions/form_access, immutability trigger, publish/rollback/retire/diff,
  import/export, 3 templates, lossless BuilderMapper (`_source`, ADR 0022), Filament builder pages.
- Fixes: absent-sentinel leak in FieldCodec, idempotent trigger migration, worker --memory in tests.
- 2525 tests green; PHPStan lvl 8 app / lvl 10 SDK + Forms/Schema.

## 2026-10-16 M1 gate
- Three reviewer subagents (security, correctness, SDK conformance). Fixed: clone identity/refs, typed
  defaults, numbering removal, save halt, boolean/option rules, nested groups, audit diffs, grant drift,
  diff auth; spec hardening (regex subset, limits, calc order) + 140 cases, both SDKs re-aligned.
- Gate: PHP 2837 tests, JS 604, coverage 95.62 % (SDK 99.69, Schema 100), E2E 4/4. Report milestones/M1.md.

## 2026-10-16 M2 implementation
- Backend API (151f575): submissions with client UUIDs, events, attachments, gap-free numbers,
  idempotency keys, devices, projector, status machine (ADR 0025/0026), SPA/preview serving, contract doc.
- PDF importer (db19a37, ADR 0024), Scramble OpenAPI gated (32c41ac), M3 breakdown in PLAN.
- Frontend subagent: form-schema-react renderer, Field App, preview entry (8b3f0e5; 214 tests, ~97 % cov).
- E2E (5cb36c5): Field App phone journey found a contract mismatch (submission responses not wrapped
  in `{ submission }`) → fixed backend. Preview shows title; admin E2E reuse sessions (login throttle).

## 2026-10-16 M2 gate
- Reviews (security, backend correctness, frontend races/a11y): 5 high + ~20 medium/low fixed via two
  parallel fix subagents (f6f8ccf, b084e9f) and directly (0925fa3 device sessions, 2613bde readOnlyIf).
- E2E found 3 real bugs: unwrapped `{ submission }`, repeater table widening phones, template key race.
- Gate: PHP 3016 tests, coverage 94.77 %, PHPStan 0/0, JS 606/110/164, conformance 391, E2E 9/9 ×2.
  Report milestones/M2.md. ADR 0027; QUESTIONS #9–11.

## 2026-10-17 M3 implementation
- Three parallel subagents (own test DBs, strict file ownership): mail (ede6e34), admin inbox +
  templates + CSV invitations (63d5aca), documents: branding, designs, Gotenberg renditions, builder
  PDF preview (7abcf4b, ADR 0028). Shared routes/web.php committed per batch by staging a split copy.
- Contract for send-to-customer (b56114a); backend + Field App agents implement it in parallel.

## 2026-10-17 M3 gate
- Integration: send to customer (API + Field App + admin), inbox documents, company details single
  source, demo branding; E2E journey submit → PDF → send → Mailpit → inbox.
- Reviews (security, correctness, UX/a11y): 3 high + ~25 medium/low fixed by two fix subagents
  (9589753, c3051c6). Gate also fixed tooling: Mailpit for worker, coverage container services, CI Rector
  drift (0f68661).
- Gate: PHP 3346 tests, coverage 95.09 %, PHPStan 0/0, Rector/Pint clean, JS 606/110/213, E2E 12/12.
