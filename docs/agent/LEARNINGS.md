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
15. Livewire 4 caps dot-path depth at 10 and payloads at 1 MB (config livewire.payload); the nested Builder needs ~25 — overridden in config/livewire.php.
16. Parallel agents: use DB_DATABASE=form_schema_test_b|c php artisan test (phpunit.xml <env> does not override exported env vars).
17. Rector's LARAVEL_130 set rewrites model $fillable/$hidden into #[Fillable]/#[Hidden] attributes — edit the attribute, not a property.
18. While subagents work in the same tree, stage explicit file paths only (never `git add -A -- app resources`): it sweeps in their unfinished files.
19. qpdf `--json-key=acroform` reads `alternativename` (/TU) and `choices` (/Opt) from the widget, so pdf-lib/Acrobat fields with a separate widget kid come back with the full name and `[]`. Read /TU, /Opt, /MaxLen and /AA from the terminal field dict (nearest /T in the /Parent chain) via `--json-key=qpdf` (ADR 0024).
20. pdf-lib radio groups put option strings in /Opt and name appearance states "0", "1", …; a push button's /AP /N is a stream, not a state dict (don't read its keys as export values).
21. pdf-lib 1.17.1 cannot create signature fields or encrypt: the fixture builds the /Sig field dict by hand; encrypted fixtures come from `qpdf --encrypt` (`--deterministic-id` is refused with encryption, so regenerating changes encrypted-*.pdf bytes — restore them from git when nothing else changed).
22. Not reproducible with generated fixtures (no real Acrobat files): exact Acrobat script spellings beyond the AF* calls tested, Simplified Field Notation, JS streams with non-Flate filters, text in Type0/CID fonts or form XObjects (labels then fall back to the field name), check-box groups with distinct export values. Handled by text patterns/heuristics and covered with synthetic inputs only.
23. Storage::fake('local') wipes storage/framework/testing/disks/local in every concurrent test run; tests that hand files to an external process (qpdf) use their own fake disk name.
24. qpdf on the host: `QPDF_BINARY=scripts/qpdf` (phpunit.xml) runs a local qpdf or `docker run form-schema-app-ci qpdf` (~0.2–0.5 s per call, paths resolved with `pwd -P` because macOS /tmp is a symlink). PDF tests no longer skip (supersedes the skip note in #3); unit tests use the stored `tests/Fixtures/pdf/*.qpdf.json` dumps.
25. Filament throttles admin logins per IP (5/min, key in the cache). E2E signs admins in once in a setup project (tests/e2e/auth.setup.ts → storage/playwright-auth) and reuses the session; re-running the suite within a minute can still trip it — `docker compose exec app php artisan cache:clear` resets the limiter.
26. Playwright's scroll-into-view fights `position: sticky` footers ("… subtree intercepts pointer events") although `elementFromPoint` shows the button on top. Use focus + Enter for sticky footer buttons in E2E; real taps are unaffected.
27. Livewire re-renders replace the builder preview iframe (wire:key = md5 of the definition); only `live()` fields trigger a re-render, so builder settings that the preview shows need `live(onBlur: true)`.
28. Submit runs inside the idempotency middleware's transaction (ADR 0027): queue `after_commit` is false, so anything dispatched during a submit must use `DB::afterCommit()` or `->afterCommit()`, or workers see uncommitted rows.
