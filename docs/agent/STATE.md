# State: 2026-10-18T00:20Z
Milestone: M6 Offline-first · Branch m6-offline (M5 merged, tag m5-done)
Foundation committed: d84ac86 (Dexie db, triggers, stubs, ADR 0035), bf7c2af (scope helper).
Tasks in flight (3 subagents, shared working tree, nothing committed by them):
- A: local-first autosave, outbox + sync engine, conflicts, queued submit/send (T02, T03, T05).
- B: PWA shell (vite-plugin-pwa), catalog cache + manifest, local-first attachments, install
  prompts (T04, T06, T07 part).
- C: backend (membership deactivation → wipe, durable send idempotency, client_submitted_at,
  manifest sees retired versions), totals conformance case (T09), offline start, logout/wipe,
  Settings storage + Sync now, SyncStatusBadge (T07, T08) — DB form_schema_test_b.
Frozen (orchestrator only): src/lib/db/**, triggers.ts, en/de.ts, queries.ts, package files,
test setup/utils, docker/compose/config, routes/api.php. Ownership: docs/agent/milestones/M6.md.
Last commit: see `git log -1`

## Next actions
1. Review each hand-back; apply requested frozen-file changes; commit per agent (explicit paths).
2. Integration: Docker rebuild; E2E journeys 3 and 7, killed tab, deactivated-user wipe (T10).
3. Gate M6 (T11): coverage, PHPStan, Rector, Pint, JS, conformance, E2E ×2, three reviews, M6.md.

## Known problems
- Builder page heavy (QUESTIONS #8); preview iframe same-origin (QUESTIONS #10, M8).
- E2E: `npm run e2e`; `php artisan cache:clear` in the app container between quick reruns.
- E2E runs rewrite evidence PNGs of earlier milestones: `git checkout` them before committing.

## Resume commands
docker compose up -d
DB_DATABASE=form_schema_test php artisan test --compact --parallel
vendor/bin/rector --dry-run --no-progress-bar   # CI gate, must be 0 files
