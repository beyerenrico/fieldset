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
