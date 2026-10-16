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
26. A sticky footer that "intercepts pointer events" in Playwright on a phone viewport was a real bug: `position: absolute` sr-only labels inside an unpositioned `overflow-x: auto` table wrapper widened the page (769 px on a 412 px phone), so the layout viewport grew and the sticky footer sat below the screen. Check `document.documentElement.scrollWidth` vs `innerWidth` first; give overflow containers `position: relative`. (Supersedes the earlier focus+Enter workaround.)
27. Livewire re-renders replace the builder preview iframe (wire:key = md5 of the definition); only `live()` fields trigger a re-render, so builder settings that the preview shows need `live(onBlur: true)`.
28. Submit runs inside the idempotency middleware's transaction (ADR 0027): queue `after_commit` is false, so anything dispatched during a submit must use `DB::afterCommit()` or `->afterCommit()`, or workers see uncommitted rows.
29. PHPStan types `Carbon::locale()` as `string|static`; use `->settings(['locale' => …])` for a localized Carbon.
30. Model strict mode (lazy-loading prevention) also bites policies called on collection items: preset relations with `setRelation()` or eager-load before authorizing each row.
31. `documents.render_on_submit` is off in testing (no Gotenberg calls from unrelated tests); tests that need renditions (send to customer, documents) must enable it.
32. Run `vendor/bin/rector --dry-run` in every gate: CI runs it and it had silently drifted on ~70 files. Skipped rules (rector.php): unused-parameter removal on policies (Gate passes args positionally), arrow-fn return types in tests (infers Pest mixin class), nullsafe coalesce (PHPStan nullsafe.neverNull).
33. The coverage container runs at base path /app: string-replacing base_path() corrupts any text containing "/app/" — anchor path stripping to path starts.
34. `workflows.complete_without_review` (submitted → completed when no workflow reviews) is off in testing like `documents.render_on_submit`; workflow tests switch it on. In Docker/local it is on, so E2E submissions without a review workflow end up `completed`.
35. Filament Builder E2E: blocks start collapsed and only expanded items render their inputs — scope locators to `.fi-fo-builder-item` filtered by the block heading; Livewire update URLs are `/livewire-<hash>/update`, not `/livewire/update`.
36. `apiHeaders()` (Sanctum::actingAs) switches the default guard to `sanctum`; a later `actingInAdmin()` in the same test then fails in Filament's TenantSet (null user) — call `auth()->shouldUse('web')` first.
37. Filament shows a disabled action with a reason only when the policy returns `Response::deny('message')` and the action has `->authorizationTooltip()`; a plain `false` hides it.
38. Status-change workflow starts are queued (StartWorkflowsForEvent, ADR 0032 amendment); with the sync queue they still run inline after commit, so tests see runs right after a submit. Mounted-action form values are not in the Livewire HTML — assert them with `assertActionDataSet()`.
36b. `SET LOCAL` outlives a released savepoint (RefreshDatabase transactions) — reset it explicitly in EXPLAIN tests.
36c. Server-side prepares: a GROUP BY that repeats a bound-parameter expression fails — group by the output alias.
39. `cache.serializable_classes` is false: cached values must be plain arrays (objects do not come back) — round-trip DTOs with toArray()/fromArray().
40. Filament widgets run `canView()` on every Livewire request (CanAuthorizeAccess boots before hydrate), not only on mount: a widget whose class stops being offered fails its next poll with 403.
41. pg_trgm GIN (1.6) also serves `=`: after adding a trigram index, "unindexed column" probes on that column get an index plan.
42. The admin panel has no custom Filament theme, so Tailwind utility classes in our own Blade views (text-gray-500, py-2, flex…) are NOT compiled and render unstyled. Use Filament components / `fi-*` classes or inline styles in custom views.
43. Filament widgets are lazy: E2E must scroll them into view before asserting their content.
44. Laravel `once()` keys the memo by the caller's file/line and the closure's `use` variables: pass the varying input (form id, user) through `use`, or every call returns the first result.
45. Strict mode throws on lazy loading only when a query returned several models — code tested with one record (e.g. one scheduled report per organization) passes and fails in production; eager-load in every loop over query results, and do not let `report($e)` hide it in tests.
46. Filament action buttons have no wire:key: two adjacent actions with complementary visible() (pin/unpin) are morphed in place by Livewire, so keyboard focus stays on the button after toggling.
