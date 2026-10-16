# State: 2026-10-17T20:00Z
Milestone: M4 Workflows + notifications → MVP 0.1.0 · Branch m4-workflows · Gate in progress
Done: M4-T01..T10 committed (engine, steps, triggers, review tasks, Filament UI, notifications, tasks API,
Field App tasks/review/notifications, demo workflow, E2E journeys 1, 4, 5 — 18/18 E2E green).
Gate so far: PHP 3538 tests, coverage 94.8 %, PHPStan/Rector/Pint clean. Three reviews done (no
critical/high). Two fix subagents running (backend on DB form_schema_test_b; Field App JS only);
nothing committed by them.
Release docs drafted: CHANGELOG.md (0.1.0), README (aae7dbe); release notes docs/releases/0.1.0.md TODO.
Last commit: see `git log -1`

## Next actions
1. Review + commit both fix batches (stage explicit paths; restore evidence PNGs of earlier milestones).
2. Rebuild Docker, migrate:fresh --seed, cache:clear, full E2E; coverage.sh; JS checks; conformance.
3. docs/agent/milestones/M4.md, docs/releases/0.1.0.md, tick M4-T11/T12, JOURNAL; root package.json
   version 0.1.0; merge --no-ff to main, tag m4-done and v0.1.0; branch m5-reports; break down M5.

## Known problems
- Builder page heavy (QUESTIONS #8); preview iframe same-origin (QUESTIONS #10, M8).
- E2E: Filament login throttle — `php artisan cache:clear` in the app container between quick reruns.

## Resume commands
docker compose up -d
DB_DATABASE=form_schema_test php artisan test --compact
vendor/bin/rector --dry-run --no-progress-bar   # CI gate, must be 0 files
