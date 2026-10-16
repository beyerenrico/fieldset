# State: 2026-10-16T23:00Z
Milestone: M3 Documents + email · Branch m3-documents (M2 merged, tag m2-done)
Tasks in flight (3 subagents, shared working tree, nothing committed by them):
- Documents agent: M3-T01..T05 (branding page, designs + versions, renderer, renditions, preview PDF)
  — tests on DB form_schema_test_b.
- Mail agent: M3-T06/T07 (mail settings, mail log + retry, branded mailables, document links 410)
  — tests on DB form_schema_test_c.
- Admin agent: M3-T09..T11 (submissions inbox, 3 templates, CSV invitations) — DB form_schema_test_d.
Committed prerequisites: spatie/laravel-pdf ^2.14, Branding value object (7e1a806).
Last commit: see `git log -1`

## Next actions
1. As each agent reports: review its diff, run its tests on a free DB, stage its listed paths
   explicitly, commit.
2. Integration (after all three): implement Rendition as DocumentDelivery file, M3-T08 send to
   customer (`POST /submissions/{uuid}/send` + admin action), renditions in the inbox "Documents"
   section, demo seed (design, branding), docs/api update.
3. E2E M3 journey: submit in Field App → PDF in admin → email in Mailpit (with PDF); rebuild Docker.
4. Gate M3: coverage.sh, PHPStan 0/0, JS checks, conformance, E2E, reviewers, M3.md, merge, tag.

## Known problems
- Builder page heavy (QUESTIONS #8); preview iframe same-origin (QUESTIONS #10, M8).
- E2E: Filament login throttle — `php artisan cache:clear` in the app container between quick reruns.

## Resume commands
docker compose up -d postgres redis gotenberg mailpit app queue scheduler
DB_DATABASE=form_schema_test php artisan test --compact   # default DB is free while agents run
npm run conformance
