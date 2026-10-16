# State: 2026-10-17T12:00Z
Milestone: M4 Workflows + notifications → MVP 0.1.0 · Branch m4-workflows (M3 merged, tag m3-done)
Tasks in flight (3 subagents, shared working tree, nothing committed by them):
- Workflow agent: M4-T01..T05 + T07 (engine, steps incl. webhook SSRF guard, triggers, review tasks,
  Filament workflow/tasks UI, tasks API) — DB form_schema_test_b.
- Notifications agent: M4-T06 (real Notifier: database + branded mail, preferences, notification API,
  form-available event, admin bell polling) — DB form_schema_test_c.
- Field App agent: M4-T08 (open tasks, review panel, changes-requested + resubmit, notification centre,
  preferences) — JS only.
Committed contracts: Notifier interface + events + message (4ec2017), API rows for tasks/notifications.
Last commit: see `git log -1`

## Next actions
1. As each agent reports: review its diff, run its tests on a free DB (default form_schema_test is free),
   stage its listed paths explicitly (routes/api.php and ApiRouteCases.php are shared), commit.
2. M4-T09 demo seed (reviewer group, review→email workflow), M4-T10 E2E journeys 4, 5, 1 (enable
   registration in the dev stack), rebuild Docker.
3. Gate M4 (coverage.sh, PHPStan 0/0, Rector + Pint clean, JS, conformance, E2E, three reviewers),
   M4.md; M4-T11 release 0.1.0 (CHANGELOG, README, tag v0.1.0); merge, tag m4-done.

## Known problems
- Builder page heavy (QUESTIONS #8); preview iframe same-origin (QUESTIONS #10, M8).
- E2E: Filament login throttle — `php artisan cache:clear` in the app container between quick reruns.

## Resume commands
docker compose up -d
DB_DATABASE=form_schema_test php artisan test --compact
vendor/bin/rector --dry-run --no-progress-bar   # CI gate, must be 0 files
