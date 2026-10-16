# AGENTS.md — form-schema-app

Autonomous build. Spec: `docs/agent/BUILD_PROMPT.md` (never edit). No human is available:
never ask, never wait. Decide per spec §0/§14.9, record ADRs in `docs/adr/`, log human-review
items in `docs/agent/QUESTIONS.md`.

## Restart protocol (every new session / after compaction) — BUILD_PROMPT §16.3
1. Read this file, then `docs/agent/STATE.md`, then `docs/agent/PLAN.md`.
2. Read the last 5 entries of `docs/agent/JOURNAL.md`, then `docs/agent/LEARNINGS.md`.
3. `git status` and `git log --oneline -15`. Uncommitted changes: finish or discard per STATE.md;
   if STATE.md does not describe them, save them on a branch first.
4. Start services, run the fast suite, confirm STATE.md matches reality (fix STATE.md if not).
5. Re-read the BUILD_PROMPT sections the current task depends on.
6. Continue with the first "Next actions" item in STATE.md.

## Memory files (§16.2)
- `docs/agent/PLAN.md` — tasks + checkboxes (single record of progress)
- `docs/agent/STATE.md` — handover, overwritten, ≤60 lines, updated every commit
- `docs/agent/JOURNAL.md` — append-only, ≤10 lines/entry
- `docs/agent/LEARNINGS.md` — gotchas; check before retrying a fix
- `docs/agent/QUESTIONS.md` — human review later; never blocks
- `docs/agent/milestones/Mx.md` — gate reports; evidence in `milestones/evidence/Mx/`

## Key commands
```
docker compose up -d postgres redis gotenberg mailpit   # dev services (host ports in .env.example)
php artisan test --compact                               # full PHP suite (Pest 5)
php artisan test --compact --filter=Name                 # focused
vendor/bin/phpstan analyse --memory-limit=1G             # Larastan
vendor/bin/pint --test                                   # formatting
npm run test -w packages/form-schema-js                  # JS unit tests
npm run conformance                                      # PHP + JS conformance runners
npm run -w resources/field-app build                     # Field App build
```
Long output → `storage/agent-logs/` (git-ignored); read only the tail.

## Conventions
- Domain code in `app/Domain/<Module>`; Filament/API/jobs stay thin and call Actions.
- Every tenant-owned model uses `BelongsToOrganization`; the scope throws without a tenant.
  Platform code opts out with `withoutTenant()`. Never `withoutGlobalScopes()` in tenant code.
- Spec semantics change ⇒ update `spec/form-definition/1.0/{README,CHANGELOG,meta-schema.json}`
  and `spec/conformance/` in the same commit.
- Money/number submission values are decimal strings; never floats.
- TDD with Pest; files <400 lines (max 800); functions <50 lines; nesting ≤4.
- Conventional commits. One branch per milestone (`m0-foundation`, …); merge `--no-ff` to
  `main` after the gate, tag `mX-done`. Never force-push or rewrite `main`.
- Commit trailer: `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.
- Never weaken, skip or delete a test or lower a gate to get green.
- Stay inside this repo and its Docker services. No global config changes.
