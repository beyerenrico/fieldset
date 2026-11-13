# State: 2026-11-13 — maintenance pass done on PRs #5–#9 (none merged); 2.1.0 is the published stable
v2 run complete (2.0.0, local tag `v2.0.0`, never published; `docs/agent/FINAL_REPORT_V2.md`). Renamed to Fieldset
(ADR 0092). Published tags, each human-approved and pushed by name: `v2.1.0-rc.1` to `rc.4` and `v2.1.0` (2c224db8).
Local only: `v2.0.0`, `rename-done`, `mX-done`. Gate report `docs/agent/milestones/R2.1.md`.

Repo `beyerenrico/fieldset`; the agent may push branches (never tags without approval, never force). Delivery of this
pass (human: "Branches + PRs"): the agent opens pull requests, the human merges. WSL parity (2.1.1) is merged (PR #1).
`maint-*` branches run CI twice with a pull request (LEARNINGS 186).

## Next actions
1. Human merges #5 `maint-ci-green` first, then #6 `maint-journey-tiers`, #7 `maint-docs-2.1`, #8 `maint-hardening`
   and #9 `maint-records` (all stacked on #5). Before #6: read the `tier: full` rerun of J09 (run 43475292965).
2. Decide a 2.1.1 release (QUESTIONS 82c): adopted instances whose Postgres image was pulled after 2026-11-12 cannot
   restore their `safety-adopt` backup until the image's client is 16.16. Needs human approval for any tag.
3. 2.1.1 WSL human steps: run `scripts/wsl/facts.sh` on Windows (QUESTIONS 81), approve `v2.1.1-rc.1`, run the
   RELEASING WSL checklist; six open P2 findings in `docs/residual-review-findings/maint-wsl-parity.md`.
4. Nightly `Install journeys` (02:17 UTC, smoke set now): fix a red one first.
   The full suite runs with `tier: all` on release.
5. Exceptions: 7 expire 2026-12-13 (gotenberg, caddy, cloudflared, one postgres), 25 on 2027-01-16: refresh pins
   (Gotenberg 9 is the open choice, QUESTIONS 82b) or renew in a reviewed commit.
6. 2.1.2 query tuning (QUESTIONS 41/74/80; plan U9–U13): saved reports, explorer search, inbox, API in_review.

## Gate results (this pass)
- #5: CI green (php-tests, audit, image-scan, e2e); one `image` build re-run after a TLS timeout to postgresql.org.
- Install journeys `all` run 43456806241 and pull request smoke run 43456746164: shards 9.8–24.0 min, budget 66.1.
- Full local suites, about 7,900 tests, on each branch; `SearchPlanTest` flaked once under `--parallel` (LEARNINGS 185).
- NOT yet read: `tier: full` rerun for J09 (run 43475292965). Read it before merging #6.
- The `0:0` ops-container path (Docker Desktop, rootless, `rules`) has no runner journey (ADR 0097).

## Pins now (ADR 0093 amended)
- golang 1.27.3-bookworm, Docker CLI 29.9.2, FrankenPHP 1.13.5 (PHP 8.4.27, Caddy 2.11.9, Go 1.27.4), Compose 5.6.0
  (built with x/net v0.62.0); Postgres client 16.16 in the app and CI images.
- postgres 16.16-alpine (tag and digest), valkey 8.1.11-alpine, gotenberg 8.40.0-chromium, mailpit v1.33.1,
  caddy 2.11.9-alpine, cloudflared 2026.11.0. Scan exceptions 32 (was 140); mailpit has no file.

## Decisions taken
- Install journeys: smoke on pull requests and nightly, `full` and `budget` shards on demand, `all` before a release
  (ADR 0096). Real-Docker coverage was cut on the human's word; 42 engine-only rows are release-only.
- Hardening (ADR 0097): mail payload allow-list, import baseline, `DomainRefusal`, line limit 300, ops capabilities.
- Journeys write release evidence to `R<major>.<minor>` (`FIELDSET_EVIDENCE_MILESTONE`); emulated runs overwrite the
  native J01/J07 files: copy them aside and restore (see JOURNAL 2026-10-23).

## Known problems
- E2E runs rewrite evidence PNGs: revert only PNGs (LEARNINGS 159). m6-offline click flake under load (QUESTIONS 76c).
- A git worktree needs `public/build`, `public/app`, `public/form-preview` copied (LEARNINGS 184).
- Open questions: QUESTIONS 82 (a, b, c, f, h, j need an answer).

## Resume commands
COMPOSE_PROFILES=sso docker compose up -d --wait
DB_DATABASE=fieldset_test php artisan test --compact --parallel
vendor/bin/pest --testsuite=Install --group=smoke   # real Docker, smoke set; --group=full, --group=budget on demand
FIELDSET_EVIDENCE_MILESTONE=R2.1 FIELDSET_REQUIRE_DOCKER=1 vendor/bin/pest --testsuite=Install   # everything
npm run e2e -- --reporter=line --workers=2
vendor/bin/rector --dry-run --no-progress-bar   # must be 0 files
