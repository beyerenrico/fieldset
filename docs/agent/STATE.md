# State: 2026-10-17T23:45Z
Milestone: M6 Offline-first · Branch m6-offline (M5 merged, tag m5-done)
Tasks in flight: none yet; M6 broken down in PLAN (M6-T01..T11).
Last commit: see `git log -1`

## Next actions
1. ADR 0035 offline architecture (Dexie schema, outbox order, sync triggers, SW scope, versions:
   Dexie 4.4.6, vite-plugin-pwa 2.0.0, Workbox 7.4.1, fake-indexeddb 6.2.6; verified on npm).
2. Dispatch Field App agents with disjoint ownership: store + autosave + outbox/sync (T01–T03, T05),
   attachments + catalog/manifest + PWA shell (T04, T06), UX + logout/wipe (T07, T08); API-side
   gaps (manifest, wipe) by a backend agent if needed.
3. Totals parity test (T09), E2E journeys 3 and 7 + killed tab + wipe (T10), gate M6 (T11).

## Known problems
- Builder page heavy (QUESTIONS #8); preview iframe same-origin (QUESTIONS #10, M8).
- E2E: run via `npm run e2e` (browsers in storage/playwright); `php artisan cache:clear` in the app
  container between quick reruns (Filament login throttle).
- E2E runs rewrite evidence PNGs of earlier milestones: `git checkout` them before committing.

## Resume commands
docker compose up -d
DB_DATABASE=form_schema_test php artisan test --compact --parallel
vendor/bin/rector --dry-run --no-progress-bar   # CI gate, must be 0 files
