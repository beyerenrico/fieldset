# LEARNINGS (gotchas; check before retrying a fix)

1. Host machine runs brew Postgres (5432), Redis (6379) and MySQL. Use Docker services on
   non-default host ports (Postgres 54329, Redis 63799, Mailpit 18025/11025, Gotenberg 13000).
2. npm `typescript@latest` is 7.0.2 (native compiler). Check typescript-eslint peer range before pinning.
3. Host tools: PHP 8.4.26 (pdo_pgsql, intl, gd, bcmath, gmp), Composer 2.8, Node 24, Docker 29,
   qpdf NOT installed on host (it lives in the queue image; tests needing it run in Docker or skip
   with a clear marker only when the binary is absent — never silently).
4. Interactive CLIs (pest --init, filament:install without flags) hang: always pass --no-interaction / write files by hand.
5. Tool output on this host is compact JSON (laravel/pao): e.g. {"tool":"pest","result":"passed",...}.
6. `Job::dispatch()` returns a PendingDispatch that dispatches on __destruct. TenantContext::run()/withoutTenant() now dispatch a returned PendingDispatch inside the tenant (guardDispatch); still prefer block closures.
7. Filament tenant association (creating hook) did not fire inside Livewire::test for the Role resource; set organization_id explicitly in actions (ManageRoles mutateDataUsing). scopedUnique() errors have no 'unique' rule key — assert on the field only.
8. Never run composer (post-autoload-dump runs filament:upgrade → view:clear) while a test run is in progress: compiled views vanish and Livewire tests fail with ViewException.
9. TypeScript 7.0 (native) is npm latest but typescript-eslint 8.71 supports <6.1: workspace pins ~6.0.3.
10. `migrate:fresh` drops tables but not PL/pgSQL functions: use CREATE OR REPLACE FUNCTION + DROP TRIGGER IF EXISTS in migrations.
11. A failing SQL statement inside RefreshDatabase's transaction aborts it (Postgres 25P02); wrap expected-to-fail raw statements in DB::transaction() (savepoint).
12. queue:work in tests stops after one job when the process exceeds --memory (default 128 MB) — pass --memory=4096 in worker tests.
13. PCOV only collects files under pcov.directory (auto = ./app) — set pcov.directory to the repo root or packages/ show 0 %.
14. `php artisan test` runs Pest in a subprocess without `php -d` ini flags (pcov.directory lost) — run coverage with vendor/bin/pest directly.
