# State: 2026-10-18T03:30Z
Milestone: M6 Offline-first · Branch m6-offline (M5 merged, tag m5-done)
Foundation committed: d84ac86 (Dexie db, triggers, stubs, ADR 0035), bf7c2af (scope helper).
Done: A/B/C merged (1f23653, 259a043, 59a3a4f), DraftLoader offline fix (a371724), demo
mobile@demo.test (9f76608). E2E tests/e2e/m6-offline.spec.ts (uncommitted): killed tab, journey 7,
wipe pass; journey 3 fails on a real bug: repeater qty values lost while typing fast (repro
tests/e2e/zz-scratch-repeater.spec.ts, scratch, delete before commit).
In flight: debugger agent (repeater value loss), three gate reviewers (security, correctness, UX).
Frozen (orchestrator only): src/lib/db/**, triggers.ts, en/de.ts, queries.ts, package files,
test setup/utils, docker/compose/config, routes/api.php. Ownership: docs/agent/milestones/M6.md.
Last commit: see `git log -1`

## Next actions
1. Debugger result → review, commit fix + regression test; delete scratch spec; commit E2E spec.
2. Review findings → fix batch (subagent), commit.
3. Gate M6 (T11): coverage, PHPStan, Rector, Pint, JS, conformance, full E2E ×2 on fresh seed, M6.md,
   merge, tag m6-done; branch m7-sso-builder; break down M7.

## Known problems
- Builder page heavy (QUESTIONS #8); preview iframe same-origin (QUESTIONS #10, M8).
- E2E: `npm run e2e`; `php artisan cache:clear` in the app container between quick reruns.
- E2E runs rewrite evidence PNGs of earlier milestones: `git checkout` them before committing.

## Resume commands
docker compose up -d
DB_DATABASE=form_schema_test php artisan test --compact --parallel
vendor/bin/rector --dry-run --no-progress-bar   # CI gate, must be 0 files
