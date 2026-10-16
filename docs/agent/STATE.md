# State: 2026-10-17T23:30Z
Milestone: M5 Reports + dashboards · Branch m5-reports (M4 merged, tags m4-done + v0.1.0 MVP)
Tasks in flight (2 subagents, shared working tree, nothing committed by them):
- Reports agent: M5-T01 explorer, T02 exports (openspout, Filament Export model, export_ready),
  T03 saved reports + scheduled mails, T06 permissions — DB form_schema_test_b.
- Dashboards agent: M5-T04 role-aware widgets + date filter + cache, T05 load generator, indexes,
  EXPLAIN tests, measurements (evidence/M5/performance.md) — DB form_schema_test_c; may seed 100k rows
  into the dev DB temporarily and restore it.
Last commit: see `git log -1`

## Next actions
1. Review + commit each batch (explicit paths; AdminPanelProvider and NotificationEvent are touched by
   one agent each).
2. Integration: pin saved reports as dashboard widgets (SavedReportQuery API + dashboard extension
   point); E2E M5-T07 (explorer across versions, export with notification, pinned report).
3. Gate M5: coverage.sh, PHPStan 0/0, Rector + Pint, JS, conformance, Docker rebuild + reseed, E2E,
   three reviewers, M5.md; merge, tag m5-done; branch m6-offline; break down M6.

## Known problems
- Builder page heavy (QUESTIONS #8); preview iframe same-origin (QUESTIONS #10, M8).
- E2E: Filament login throttle — `php artisan cache:clear` in the app container between quick reruns.

## Resume commands
docker compose up -d
DB_DATABASE=form_schema_test php artisan test --compact
vendor/bin/rector --dry-run --no-progress-bar   # CI gate, must be 0 files
