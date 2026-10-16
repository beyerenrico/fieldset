# State: 2026-10-16T19:00Z
Milestone: M2 Field App online · Tasks in flight: M2-T12..T14 (subagent: renderer + Field App), M2-T16 (subagent: PDF import)
Status: backend API done (M2-T01..T10): submissions/attachments/numbering/devices/access/visibility,
projector, sync manifest, SPA + preview serving routes, builder preview tab. Contract:
docs/api/field-app-v1.md. Two subagents are running; they commit nothing themselves.
Branch: m2-field-app · Last commit: see `git log -1`

## Next actions
1. When the frontend subagent reports: review, run `npm run build`, check /app and /form-preview in the
   Docker stack, commit packages/form-schema-react + resources/field-app.
2. When the PDF subagent reports: review, run its tests (DB form_schema_test_b), commit.
3. Then (no agents running): `composer require dedoc/scramble` (M2-T11, OpenAPI at /docs/api, gated).
4. Playwright journeys 2 (with preview) and 8 (PDF import) + a mobile Field App journey; M2 gate.

## Known problems
- Builder page is heavy (~4 MB HTML for the service report) — QUESTIONS #8.
- Dev DB `form_schema` was wiped by a migrate:fresh during M2: re-run `docker compose exec app php artisan db:seed --force` before E2E.

## Resume commands
docker compose up -d postgres redis gotenberg mailpit
DB_DATABASE=form_schema_test_c php artisan test --compact   # while agents use the other test DBs
npm run conformance
